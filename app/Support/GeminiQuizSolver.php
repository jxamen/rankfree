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

    // 재시도 대상 HTTP 상태.
    private const RETRYABLE_STATUS = [429, 500, 503];

    private const DEFAULT_INSTRUCTION =
        "제시된 이미지는 영수증이며 품목별 수량·금액이 표(열)로 적혀 있다. 질문에 맞는 값을 계산해 숫자만 답하라.\n".
        "- '총 몇 개'처럼 개수를 물으면: 수량 열(숫자가 가장 작은 열)의 값들을 모두 더한 합을 답한다.\n".
        "- '총 구매 금액'처럼 금액을 물으면: 금액 열(숫자가 가장 큰 열)의 값들을 모두 더한 합을 답한다.\n".
        '쉼표·단위·기호 없이 아라비아 숫자만 답하고, 그 밖의 설명은 절대 하지 마라.';

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

        // 캡차 이미지 분석은 정확도 위해 전용(더 강한) 모델을 쓴다 — 없으면 일반 모델로 폴백.
        $model = trim((string) config('services.gemini.quiz_model', '')) ?: $cred['model'];
        $endpoint = sprintf(self::ENDPOINT_TPL, $model);

        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => $parts],
            ],
            'generationConfig' => [
                'temperature' => 0,
            ],
        ];

        $res = null;
        for ($attempt = 1; $attempt <= self::RETRY_ATTEMPTS; $attempt++) {
            try {
                $res = Http::timeout(self::TIMEOUT)
                    // 이 서버는 IPv6 아웃바운드가 죽어 있고 googleapis는 IPv6 우선 해석이라
                    // 앱(php-fpm) cURL이 IPv6에 물려 간헐적 타임아웃이 난다 — IPv4 강제.
                    ->withOptions(['force_ip_resolve' => 'v4'])
                    ->withHeaders([
                        'x-goog-api-key' => $cred['key'],
                        'Content-Type' => 'application/json',
                    ])
                    ->post($endpoint, $payload);
            } catch (\Throwable $e) {
                Log::warning('[GeminiQuizSolver] request failed', ['attempt' => $attempt, 'error' => $e->getMessage()]);
                // 네트워크 예외도 마지막 시도 전까지 재시도.
                if ($attempt < self::RETRY_ATTEMPTS) {
                    self::backoff($attempt);

                    continue;
                }

                return ['ok' => false, 'answer' => null, 'error' => 'Gemini 요청 실패: '.$e->getMessage()];
            }

            // 과부하/rate limit/일시 오류면 백오프 후 재시도.
            if (in_array($res->status(), self::RETRYABLE_STATUS, true) && $attempt < self::RETRY_ATTEMPTS) {
                Log::info('[GeminiQuizSolver] retryable status, backing off', ['attempt' => $attempt, 'status' => $res->status()]);
                self::backoff($attempt);

                continue;
            }

            break;
        }

        if (! $res->successful()) {
            $status = $res->status();
            if (in_array($status, self::RETRYABLE_STATUS, true)) {
                return [
                    'ok' => false,
                    'answer' => null,
                    'error' => 'Gemini 서버가 일시적으로 혼잡합니다('.$status.'). 잠시 후 다시 시도하세요.',
                ];
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
