<?php

namespace App\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Routes seller-info captcha quiz solving to the selected vision model provider.
 * The return shape matches GeminiQuizSolver: {ok, answer, error}.
 */
class QuizSolver
{
    private const TIMEOUT = 30;

    private const DEFAULT_MODEL = 'gemini-pro-latest';

    /** Selected quiz model. */
    public static function model(): string
    {
        return trim((string) config('rankfree.quiz.model', '')) ?: self::DEFAULT_MODEL;
    }

    /** Infer provider by model prefix. */
    public static function providerOf(string $model): string
    {
        $m = strtolower(trim($model));

        return match (true) {
            $m === '', str_starts_with($m, 'gemini') => 'gemini',
            str_starts_with($m, 'claude') => 'anthropic',
            str_starts_with($m, 'grok') => 'xai',
            str_starts_with($m, 'gpt'),
                str_starts_with($m, 'chatgpt'),
                str_starts_with($m, 'o1'),
                str_starts_with($m, 'o3'),
                str_starts_with($m, 'o4') => 'openai',
            default => 'gemini',
        };
    }

    /** Whether the selected model's provider key is configured. */
    public static function configured(): bool
    {
        return trim((string) config(self::keyPath(self::providerOf(self::model())), '')) !== '';
    }

    private static function keyPath(string $provider): string
    {
        return match ($provider) {
            'anthropic' => 'services.anthropic.key',
            'openai' => 'services.openai.key',
            'xai' => 'services.xai.key',
            default => 'services.gemini.key',
        };
    }

    /**
     * @param  array<int, string|array{mime?:string,data:string}>  $images
     * @return array{ok:bool, answer:?string, error:?string}
     */
    public static function solve(string $question, array $images, ?string $instruction = null): array
    {
        $model = self::model();
        $provider = self::providerOf($model);

        return match ($provider) {
            'gemini' => GeminiQuizSolver::solve($question, $images, $instruction, $model),
            'openai' => self::solveOpenAiCompatible($question, $images, $instruction, $model, 'openai'),
            'xai' => self::solveOpenAiCompatible($question, $images, $instruction, $model, 'xai'),
            'anthropic' => self::solveClaude($question, $images, $instruction, $model),
            default => ['ok' => false, 'answer' => null, 'error' => 'Unsupported model: '.$model],
        };
    }

    /** OpenAI and xAI Grok via the OpenAI-compatible Chat Completions API. */
    private static function solveOpenAiCompatible(string $question, array $images, ?string $instruction, string $model, string $provider): array
    {
        $label = $provider === 'xai' ? 'Grok(xAI)' : 'OpenAI';
        $key = trim((string) config(self::keyPath($provider), ''));
        if ($key === '') {
            return ['ok' => false, 'answer' => null, 'error' => $label.' API key is not configured.'];
        }

        $endpoint = $provider === 'xai'
            ? 'https://api.x.ai/v1/chat/completions'
            : 'https://api.openai.com/v1/chat/completions';

        $content = [];
        if (trim($question) !== '') {
            $content[] = ['type' => 'text', 'text' => trim($question)];
        }

        $imageCount = 0;
        foreach ($images as $img) {
            $norm = self::normalizeImage($img);
            if ($norm === null) {
                continue;
            }

            $imageCount++;
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:'.$norm['mime'].';base64,'.$norm['data']],
            ];
        }

        if (trim($question) === '' && $imageCount === 0) {
            return ['ok' => false, 'answer' => null, 'error' => 'question or image is required.'];
        }

        $content[] = ['type' => 'text', 'text' => self::instruction($instruction)];

        $res = self::post($endpoint, ['Authorization' => 'Bearer '.$key], [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $content]],
            'temperature' => 0,
        ], $label);

        if ($res === null) {
            return ['ok' => false, 'answer' => null, 'error' => $label.' request failed (network).'];
        }

        if (! $res->successful()) {
            return ['ok' => false, 'answer' => null, 'error' => $label.' API error ('.$res->status().'): '.mb_substr($res->body(), 0, 200)];
        }

        $answer = trim((string) data_get($res->json(), 'choices.0.message.content', ''));

        return $answer === ''
            ? ['ok' => false, 'answer' => null, 'error' => $label.' returned an empty answer.']
            : ['ok' => true, 'answer' => $answer, 'error' => null];
    }

    /** Claude via the Anthropic Messages API. */
    private static function solveClaude(string $question, array $images, ?string $instruction, string $model): array
    {
        $key = trim((string) config('services.anthropic.key', ''));
        if ($key === '') {
            return ['ok' => false, 'answer' => null, 'error' => 'Claude API key is not configured.'];
        }

        $content = [];
        if (trim($question) !== '') {
            $content[] = ['type' => 'text', 'text' => trim($question)];
        }

        $imageCount = 0;
        foreach ($images as $img) {
            $norm = self::normalizeImage($img);
            if ($norm === null) {
                continue;
            }

            $imageCount++;
            $content[] = [
                'type' => 'image',
                'source' => ['type' => 'base64', 'media_type' => $norm['mime'], 'data' => $norm['data']],
            ];
        }

        if (trim($question) === '' && $imageCount === 0) {
            return ['ok' => false, 'answer' => null, 'error' => 'question or image is required.'];
        }

        $content[] = ['type' => 'text', 'text' => self::instruction($instruction)];

        $res = self::post('https://api.anthropic.com/v1/messages', [
            'x-api-key' => $key,
            'anthropic-version' => '2023-06-01',
        ], [
            'model' => $model,
            'max_tokens' => 64,
            'temperature' => 0,
            'messages' => [['role' => 'user', 'content' => $content]],
        ], 'Claude');

        if ($res === null) {
            return ['ok' => false, 'answer' => null, 'error' => 'Claude request failed (network).'];
        }

        if (! $res->successful()) {
            return ['ok' => false, 'answer' => null, 'error' => 'Claude API error ('.$res->status().'): '.mb_substr($res->body(), 0, 200)];
        }

        $answer = trim((string) data_get($res->json(), 'content.0.text', ''));

        return $answer === ''
            ? ['ok' => false, 'answer' => null, 'error' => 'Claude returned an empty answer.']
            : ['ok' => true, 'answer' => $answer, 'error' => null];
    }

    private static function instruction(?string $instruction): string
    {
        return ($instruction !== null && trim($instruction) !== '')
            ? trim($instruction)
            : GeminiQuizSolver::DEFAULT_INSTRUCTION;
    }

    private static function post(string $url, array $headers, array $body, string $label): ?Response
    {
        try {
            return Http::timeout(self::TIMEOUT)
                // Some production hosts have broken IPv6 outbound routes.
                ->withOptions(['force_ip_resolve' => 'v4'])
                ->withHeaders($headers + ['Content-Type' => 'application/json'])
                ->post($url, $body);
        } catch (\Throwable $e) {
            Log::warning('[QuizSolver] request failed', ['provider' => $label, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  string|array{mime?:string,data:string}  $image
     * @return array{mime:string,data:string}|null
     */
    private static function normalizeImage($image): ?array
    {
        if (is_array($image) && isset($image['data'])) {
            $data = trim((string) $image['data']);

            return $data === '' ? null : ['mime' => (string) ($image['mime'] ?? 'image/png'), 'data' => $data];
        }

        if (! is_string($image)) {
            return null;
        }

        $image = trim($image);
        if ($image === '') {
            return null;
        }

        if (preg_match('/^data:(image\/[-+.a-zA-Z0-9]+);base64,(.+)$/s', $image, $m)) {
            return ['mime' => strtolower($m[1]), 'data' => $m[2]];
        }

        return ['mime' => 'image/png', 'data' => $image];
    }
}
