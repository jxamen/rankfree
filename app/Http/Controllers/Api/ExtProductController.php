<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 크롬 확장 — 상품 분석(리뷰 분석) 저장/내역 API. */
class ExtProductController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'origin_product_no' => ['required', 'integer', 'min:1'],
            'merchant_no' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:200'],
            'url' => ['required', 'string', 'max:500'],
            'store' => ['nullable', 'string', 'max:80'],
            'total_reviews' => ['required', 'integer', 'min:0'],
            'analyzed_reviews' => ['required', 'integer', 'min:0'],
            'avg_score' => ['required', 'numeric', 'min:0', 'max:5'],
            'repurchase_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'recent_7d' => ['required', 'integer', 'min:0'],
            'recent_1m' => ['required', 'integer', 'min:0'],
            'recent_3m' => ['required', 'integer', 'min:0'],
            'sales_6m' => ['nullable', 'integer', 'min:0'],
            'price' => ['nullable', 'integer', 'min:0'],
            'snapshot' => ['required', 'array'],
            'report_html' => ['nullable', 'string', 'max:400000'], // 확장 렌더 리포트(내역 재생용)
        ]);

        if (strlen((string) json_encode($data['snapshot'])) > 120000) {
            return response()->json(['message' => '스냅샷 데이터가 너무 큽니다.'], 422);
        }

        if (! $request->user()->tryConsumeFeature('product_analysis')) {
            return response()->json([
                'ok' => false,
                'limit_exceeded' => true,
                'message' => '이번 달 상품 리뷰 분석 저장 횟수('.$request->user()->featureLimit('product_analysis').'회)를 모두 사용했습니다. 요금제를 업그레이드하세요.',
            ], 429);
        }

        $analysis = $request->user()->productAnalyses()->create($data);

        return response()->json(['ok' => true, 'id' => $analysis->id, 'share_token' => $analysis->shareToken()]);
    }

    public function index(Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->query('limit', 20)));

        return response()->json([
            'data' => $request->user()->productAnalyses()->latest()->limit($limit)->get([
                'id', 'origin_product_no', 'name', 'store', 'url', 'total_reviews', 'analyzed_reviews',
                'avg_score', 'repurchase_pct', 'recent_1m', 'sales_6m', 'created_at',
            ]),
        ]);
    }

    public function show(Request $request, ProductAnalysis $analysis): JsonResponse
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        $analysis->shareToken();

        return response()->json(['data' => $analysis]);
    }
}
