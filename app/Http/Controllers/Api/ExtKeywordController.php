<?php

namespace App\Http\Controllers\Api;

use App\Domain\Keyword\KeywordAnalysisPresenter;
use App\Domain\Keyword\NaverDataLabService;
use App\Domain\Keyword\NaverKeywordService;
use App\Http\Controllers\Controller;
use App\Models\KeywordSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 크롬 확장 — 키워드 분석(월간 검색량 등) API. */
class ExtKeywordController extends Controller
{
    public function show(Request $request, NaverKeywordService $service, NaverDataLabService $datalab): JsonResponse
    {
        $keyword = trim((string) $request->query('keyword', ''));

        if ($keyword === '') {
            return response()->json(['data' => null, 'message' => 'keyword 파라미터가 필요합니다.'], 422);
        }

        $data = $service->analyze($keyword);
        if ($data !== null) {
            $data['grade'] = KeywordAnalysisPresenter::grade((int) ($data['monthly_total'] ?? 0)); // 검색량 등급(콘솔 동일 지표)
            $data['weekday'] = $datalab->weekdayRatio($keyword); // 요일별 검색 비율(월~일)
        }

        // 조회 성공 시 KeywordSearch 레코드로 저장 → 공유 토큰(/k/{token}) 발급
        $shareToken = null;
        if ($data !== null && $request->user()) {
            $record = KeywordSearch::updateOrCreate(
                ['user_id' => $request->user()->id, 'keyword' => $keyword],
                [
                    'monthly_total' => (int) ($data['monthly_total'] ?? 0),
                    'monthly_pc' => (int) ($data['monthly_pc'] ?? 0),
                    'monthly_mobile' => (int) ($data['monthly_mobile'] ?? 0),
                    'comp_idx' => $data['comp_idx'] ?? null,
                    'grade' => $data['grade'] ?? null,
                    'snapshot' => $data,
                ]
            );
            $shareToken = $record->shareToken();
        }

        return response()->json([
            'data' => $data,
            'share_token' => $shareToken,
            'message' => $data === null ? '키워드 검색량 데이터를 조회하지 못했습니다.' : null,
        ]);
    }
}
