<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\QuizSolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 외부(크롬 확장 등)에서 질문 텍스트 + 보기 이미지를 받아
 * Gemini 2.5 Flash 로 풀이한 정답을 반환한다. (저장은 하지 않는다)
 *
 *   POST /api/ext/quiz/solve
 *   {
 *     "question": "영수증에서 구매한 물건은 몇 개입니까?",
 *     "images": ["data:image/png;base64,....", "data:image/png;base64,...."]
 *     // 또는 단일: "image_data": "data:image/png;base64,...."
 *     // 선택: "instruction": "정답 번호만 답하라."
 *   }
 */
class ExtQuizController extends Controller
{
    // 요청 본문 상한 (base64 이미지 여러 장 고려)
    private const MAX_BODY_BYTES = 8_000_000;

    // 보기 이미지 최대 개수
    private const MAX_IMAGES = 6;

    public function solve(Request $request): JsonResponse
    {
        // 캡차 분석은 슈퍼관리자(운영자)만 — 일반 확장 사용자에게는 열지 않는다.
        if (! $request->user()?->isSuperAdmin()) {
            return response()->json(['ok' => false, 'message' => '권한이 없습니다. 캡차 분석은 슈퍼관리자만 사용할 수 있습니다.'], 403);
        }

        if (! QuizSolver::configured()) {
            return response()->json(['ok' => false, 'message' => '선택한 퀴즈 모델('.QuizSolver::model().')의 API 키가 설정되지 않았습니다.'], 503);
        }

        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return response()->json(['ok' => false, 'message' => 'Payload is too large.'], 413);
        }

        $data = $request->validate([
            'question' => ['nullable', 'string', 'max:1000'],
            'image_data' => ['nullable', 'string'],
            'images' => ['nullable', 'array', 'max:'.self::MAX_IMAGES],
            'images.*' => ['string'],
            'instruction' => ['nullable', 'string', 'max:1000'],
        ]);

        // image_data(단일) + images(배열)을 하나의 목록으로 병합
        $images = [];
        if (! empty($data['image_data'])) {
            $images[] = $data['image_data'];
        }
        foreach ($data['images'] ?? [] as $img) {
            $images[] = $img;
        }

        $question = trim((string) ($data['question'] ?? ''));

        if ($question === '' && count($images) === 0) {
            return response()->json(['ok' => false, 'message' => 'question 또는 image 가 필요합니다.'], 422);
        }

        $result = QuizSolver::solve(
            $question,
            $images,
            $data['instruction'] ?? null,
        );

        if (! $result['ok']) {
            return response()->json([
                'ok' => false,
                'message' => $result['error'] ?? '퀴즈 풀이 실패',
            ], 502);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'question' => $question,
                'answer' => $this->extractAnswer($result['answer'] ?? null),
                'raw_answer' => $result['answer'] ?? null,
                'image_count' => count($images),
            ],
        ]);
    }

    /** 모델이 설명을 덧붙여도 실제 정답 숫자만 추출한다(마지막 줄의 숫자 우선). */
    private function extractAnswer(?string $answer): ?string
    {
        $t = trim((string) $answer);
        if ($t === '') {
            return $t;
        }
        // 전체가 숫자(쉼표·공백)면 숫자만 남긴다.
        if (preg_match('/^[\d,\s]+$/', $t)) {
            return preg_replace('/\D/', '', $t);
        }
        // 마지막 비어있지 않은 줄부터 숫자를 찾는다.
        $lines = preg_split('/\r?\n/', $t) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }
            if (preg_match_all('/-?\d[\d,]*/', $line, $mm) && ! empty($mm[0])) {
                return str_replace(',', '', end($mm[0]));
            }
        }

        return $t;
    }
}
