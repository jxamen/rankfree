<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlaceStoreAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 크롬 확장 — 플레이스 매장 분석 저장/내역 API. */
class ExtPlaceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'place_id' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:120'],
            'keyword' => ['required', 'string', 'max:100'],
            'cat' => ['nullable', 'string', 'max:30'],
            'rank' => ['nullable', 'integer', 'min:0'],
            'n1' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'n2' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'n3' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'visitor_cnt' => ['nullable', 'integer', 'min:0'],
            'blog_cnt' => ['nullable', 'integer', 'min:0'],
            'save_cnt' => ['nullable', 'integer', 'min:0'],
            'detail' => ['required', 'array'],
        ]);

        if (strlen((string) json_encode($data['detail'])) > 120000) {
            return response()->json(['message' => '분석 데이터가 너무 큽니다.'], 422);
        }

        if (! $request->user()->tryConsumeFeature('place_analysis')) {
            return response()->json([
                'ok' => false,
                'limit_exceeded' => true,
                'message' => '이번 달 플레이스 매장 분석 저장 횟수('.$request->user()->featureLimit('place_analysis').'회)를 모두 사용했습니다. 요금제를 업그레이드하세요.',
            ], 429);
        }

        // 같은 매장×키워드 재분석은 새 행 대신 갱신 — 내역이 중복으로 쌓이지 않게
        $analysis = $request->user()->placeStoreAnalyses()->updateOrCreate(
            ['place_id' => $data['place_id'], 'keyword' => $data['keyword']],
            $data,
        );

        return response()->json(['ok' => true, 'id' => $analysis->id]);
    }

    public function index(Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->query('limit', 20)));

        return response()->json([
            'data' => $request->user()->placeStoreAnalyses()->latest('updated_at')->limit($limit)->get([
                'id', 'place_id', 'name', 'keyword', 'cat', 'rank',
                'n1', 'n2', 'n3', 'visitor_cnt', 'blog_cnt', 'save_cnt', 'created_at', 'updated_at',
            ]),
        ]);
    }

    public function show(Request $request, PlaceStoreAnalysis $analysis): JsonResponse
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        return response()->json(['data' => $analysis]);
    }
}
