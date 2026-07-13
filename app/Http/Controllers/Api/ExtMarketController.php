<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 크롬 확장 — 쇼핑 시장 분석 저장/내역 API. */
class ExtMarketController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:120'],
            'total_count' => ['required', 'integer', 'min:0'],
            'item_count' => ['required', 'integer', 'min:0'],
            'include_ads' => ['boolean'],
            'sales_6m' => ['required', 'integer', 'min:0'],
            'revenue_6m' => ['required', 'integer', 'min:0'],
            'avg_price' => ['required', 'integer', 'min:0'],
            'median_price' => ['required', 'integer', 'min:0'],
            'top10_share' => ['required', 'numeric', 'min:0', 'max:100'],
            'monthly_search' => ['nullable', 'integer', 'min:0'],
            'comp_idx' => ['nullable', 'string', 'max:20'],
            'snapshot' => ['required', 'array'],
        ]);

        if (strlen((string) json_encode($data['snapshot'])) > 120000) {
            return response()->json(['message' => '스냅샷 데이터가 너무 큽니다.'], 422);
        }

        if (! $request->user()->tryConsumeFeature('market_analysis')) {
            return response()->json([
                'ok' => false,
                'limit_exceeded' => true,
                'message' => '이번 달 쇼핑 시장 분석 저장 횟수('.$request->user()->featureLimit('market_analysis').'회)를 모두 사용했습니다. 요금제를 업그레이드하세요.',
            ], 429);
        }

        $analysis = $request->user()->marketAnalyses()->create($data);

        return response()->json(['ok' => true, 'id' => $analysis->id, 'share_token' => $analysis->shareToken()]);
    }

    public function index(Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->query('limit', 20)));

        $query = $request->user()->marketAnalyses()->latest();
        $keyword = trim((string) $request->query('keyword', ''));
        if ($keyword !== '') {
            $query->where('keyword', 'like', '%'.$keyword.'%');
        }

        return response()->json([
            'data' => $query->limit($limit)->get([
                'id', 'keyword', 'total_count', 'item_count', 'sales_6m', 'revenue_6m',
                'avg_price', 'monthly_search', 'comp_idx', 'created_at',
            ]),
        ]);
    }

    public function show(Request $request, MarketAnalysis $analysis): JsonResponse
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        $analysis->shareToken();

        return response()->json(['data' => $analysis]);
    }
}
