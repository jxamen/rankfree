<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 퀴즈 질문(텍스트) + 보기(이미지들)를 Gemini 2.5 Flash에 전달하고 정답 텍스트를 반환한다.
 * API 키/모델은 App\Support\GeminiCredentials 를 통해 가져온다.
 */
class GeminiQuizSolver
{
    private const ENDPOINT_TPL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    private const TIMEOUT = 30;

    private const DEFAULT_INSTRUCTION =
        '위 질문과 보기 이미지를 보고 정답만 간결하게 답하라. 숫자 정답이면 숫자만, 보기 선택이면 보기 번호만 답하라. 불필요한 설명은 하지 마라.';

    /**
     * @param  string  $question  질문 텍스트
     * @param  array<int, string|array{mime?:string,data:string}>  $images  data URL / 순수 base64 / ['mime'=>,'data'=>]
     * @param  string|null  $instruction  모델에 줄 추가 지시(미지정 시 기본 지시 사용)
     * @return array{ok:bool, answer:?string, error:?string}
     */
    public static function solve(string $question, array $images, ?string $instruction = null): array
    {
        $cred = GeminiCredentials::credentials();
        if ($cred === null) {
            return ['ok' => false, 'answer' => null, 'error' => 'Gemini API 키가 설정되지 않았습니다.'];
        }

        $question = trim($question);

        // parts 조립: 질문 -> (보기N 라벨 + 이미지) 반복 -> 마무리 지시
        $parts = [];
        if ($question !== '') {
            $parts[] = ['text' => $question];
        }

        $imageCount = 0;
        foreach ($images as $image) {
            $norm = self::normalizeImage($image);
            if ($norm === null) {
                continue;
            }
            $imageCount++;
            $parts[] = ['text' => '보기 '.$imageCount.':'];
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $norm['mime'],
                    'data' => $norm['data'],
                ],
            ];
        }

        if ($question === '' && $imageCount === 0) {
            return ['ok' => false, 'answer' => null, 'error' => '질문 또는 유효한 이미지가 필요합니다.'];
        }

        $parts[] = [
            'text' => ($instruction !== null && trim($instruction) !== '')
                ? trim($instruction)
                : self::DEFAULT_INSTRUCTION,
        ];

        $endpoint = sprintf(self::ENDPOINT_TPL, $cred['model']);

        try {
            $res = Http::timeout(self::TIMEOUT)
                ->withHeaders([
                    'x-goog-api-key' => $cred['key'],
                    'Content-Type' => 'application/json',
                ])
                ->post($endpoint, [
                    'contents' => [
                        ['role' => 'user', 'parts' => $parts],
                    ],
                    'generationConfig' => [
                        'temperature' => 0,
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('[GeminiQuizSolver] request failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'answer' => null, 'error' => 'Gemini 요청 실패: '.$e->getMessage()];
        }

        if (! $res->successful()) {
            return [
                'ok' => false,
                'answer' => null,
                'error' => 'Gemini API 오류 ('.$res->status().'): '.mb_substr($res->body(), 0, 300),
            ];
        }

        $json = $res->json();
        $answer = self::extractText($json);

        if ($answer === '') {
            $reason = data_get($json, 'promptFeedback.blockReason')
                ?? data_get($json, 'candidates.0.finishReason')
                ?? 'empty';

            return ['ok' => false, 'answer' => null, 'error' => 'Gemini 응답 없음: '.$reason];
        }

        return ['ok' => true, 'answer' => $answer, 'error' => null];
    }

    /**
     * 이미지 입력을 { mime, data(base64) } 로 정규화한다.
     *
     * @param  string|array{mime?:string,data:string}  $image
     * @return array{mime:string,data:string}|null
     */
    private static function normalizeImage($image): ?array
    {
        if (is_array($image) && isset($image['data'])) {
            $data = trim((string) $image['data']);
            if ($data === '') {
                return null;
            }

            return ['mime' => (string) ($image['mime'] ?? 'image/png'), 'data' => $data];
        }

        if (! is_string($image)) {
            return null;
        }

        $image = trim($image);
        if ($image === '') {
            return null;
        }

        // data:image/png;base64,xxxx 형태
        if (preg_match('/^data:(image\/[-+.a-zA-Z0-9]+);base64,(.+)$/s', $image, $m)) {
            return ['mime' => strtolower($m[1]), 'data' => $m[2]];
        }

        // 순수 base64 로 간주 (mime 은 png 로 가정)
        return ['mime' => 'image/png', 'data' => $image];
    }

    /**
     * candidates[0].content.parts[].text 를 모두 이어붙여 반환한다.
     */
    private static function extractText(mixed $json): string
    {
        $parts = data_get($json, 'candidates.0.content.parts', []);
        if (! is_array($parts)) {
            return '';
        }

        $text = '';
        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $text .= $part['text'];
            }
        }

        return trim($text);
    }
}
