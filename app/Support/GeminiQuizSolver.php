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

    // 과부하(503)·rate limit(429)·일시 오류(500) 시 재시도 횟수와 기본 백오프(ms).
    private const RETRY_ATTEMPTS = 3;

    private const RETRY_BASE_MS = 700;

    // 같은 모델로 재시도(백오프)할 HTTP 상태.
    private const RETRYABLE_STATUS = [429, 500, 503];

    public const DEFAULT_INSTRUCTION =
        "제시된 이미지는 영수증이며 품목별 수량·금액이 표(열)로 적혀 있다. 질문에 맞는 값을 계산해 숫자만 답하라.\n".
        "이미지에는 숫자를 가리려고 주변에 낙서·선·점·빗금·얼룩·워터마크·배경 무늬 같은 방해 요소를 일부러 섞어 놓았을 수 있다. ".
        "이런 노이즈는 모두 무시하고, 실제로 인쇄된 영수증 숫자만 정확히 판독하라. ".
        "숫자가 겹치거나 흐릿하면 자릿수·열 정렬·행 위치를 근거로 가장 그럴듯한 값으로 읽어라.\n".
        "- '총 몇 개'처럼 개수를 물으면: 수량 열(숫자가 가장 작은 열)의 값들을 모두 더한 합을 답한다.\n".
        "- '총 구매 금액'처럼 금액을 물으면: 금액 열(숫자가 가장 큰 열)의 값들을 모두 더한 합을 답한다.\n".
        '출력은 오직 정답 숫자 하나만, 쉼표·단위·기호·설명·풀이 과정 없이 아라비아 숫자만 적어라. (예: 4700)';

    /**
     * @param  string  $question  질문 텍스트
     * @param  array<int, string|array{mime?:string,data:string}>  $images  data URL / 순수 base64 / ['mime'=>,'data'=>]
     * @param  string|null  $instruction  모델에 줄 추가 지시(미지정 시 기본 지시 사용)
     * @return array{ok:bool, answer:?string, error:?string}
     */
    public static function solve(string $question, array $images, ?string $instruction = null, ?string $primaryModel = null): array
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

        // 환경설정에 지정된 모델만 사용한다(다른 모델로 자동 폴백하지 않음).
        // 같은 모델에 대한 429/503 일시 오류만 백오프 재시도한다.
        $model = $primaryModel ?: (trim((string) config('services.gemini.quiz_model', '')) ?: $cred['model']);

        $generationConfig = ['temperature' => 0];
        // 비용 절감: 추론(thinking) 토큰이 비용의 주범 — 기본은 끈다(설정에서 켤 수 있음).
        // thinkingBudget=0 은 flash/lite 계열에서 지원(pro 계열은 강제하지 않는다).
        $thinkingOn = in_array((string) config('services.gemini.quiz_thinking'), ['1', 'true', 'on'], true);
        if (! $thinkingOn && (str_contains($model, 'flash') || str_contains($model, 'lite'))) {
            $generationConfig['thinkingConfig'] = ['thinkingBudget' => 0];
        }

        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => $parts],
            ],
            'generationConfig' => $generationConfig,
        ];

        $result = self::requestWithRetries($model, $cred['key'], $payload);
        $res = $result['res'];
        $networkError = $result['error'];

        if ($res === null) {
            return ['ok' => false, 'answer' => null, 'error' => 'Gemini 요청 실패: '.($networkError ?: 'unknown')];
        }

        if (! $res->successful()) {
            $status = $res->status();
            if (in_array($status, self::RETRYABLE_STATUS, true)) {
                $msg = $status === 429
                    ? 'Gemini 사용량 한도(429)에 도달했습니다. 잠시 후 다시 시도하거나 API 키의 결제/한도를 확인하세요.'
                    : 'Gemini 서버가 일시적으로 혼잡합니다('.$status.'). 잠시 후 다시 시도하세요.';

                return ['ok' => false, 'answer' => null, 'error' => $msg];
            }

            return [
                'ok' => false,
                'answer' => null,
                'error' => 'Gemini API 오류 ('.$status.'): '.mb_substr($res->body(), 0, 300),
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
     * 단일 모델로 요청 + 재시도(지수 백오프). 404 등은 재시도하지 않고 즉시 반환(상위에서 폴백).
     *
     * @return array{res: ?\Illuminate\Http\Client\Response, error: ?string}
     */
    private static function requestWithRetries(string $model, string $apiKey, array $payload): array
    {
        $endpoint = sprintf(self::ENDPOINT_TPL, $model);
        $res = null;
        for ($attempt = 1; $attempt <= self::RETRY_ATTEMPTS; $attempt++) {
            try {
                $res = Http::timeout(self::TIMEOUT)
                    // IPv6 아웃바운드가 죽은 서버 대비 — IPv4 강제.
                    ->withOptions(['force_ip_resolve' => 'v4'])
                    ->withHeaders([
                        'x-goog-api-key' => $apiKey,
                        'Content-Type' => 'application/json',
                    ])
                    ->post($endpoint, $payload);
            } catch (\Throwable $e) {
                Log::warning('[GeminiQuizSolver] request failed', ['model' => $model, 'attempt' => $attempt, 'error' => $e->getMessage()]);
                if ($attempt < self::RETRY_ATTEMPTS) {
                    self::backoff($attempt);

                    continue;
                }

                return ['res' => null, 'error' => $e->getMessage()];
            }

            // 429/500/503만 같은 모델로 백오프 재시도(404 등은 즉시 반환).
            if (in_array($res->status(), self::RETRYABLE_STATUS, true) && $attempt < self::RETRY_ATTEMPTS) {
                Log::info('[GeminiQuizSolver] retryable status, backing off', ['model' => $model, 'attempt' => $attempt, 'status' => $res->status()]);
                self::backoff($attempt);

                continue;
            }

            break;
        }

        return ['res' => $res, 'error' => null];
    }

    /**
     * 지수 백오프 대기 — attempt 1→700ms, 2→1400ms.
     */
    private static function backoff(int $attempt): void
    {
        $ms = self::RETRY_BASE_MS * (2 ** ($attempt - 1));
        usleep($ms * 1000);
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
