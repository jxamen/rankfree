<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KeywordShopSerp;
use Illuminate\Http\Request;

/**
 * 확장이 수집한 쇼핑 노출 상품(상위 80개)을 저장 — 서버는 search.shopping 이 418 이라 직접 수집할 수 없다.
 * 관리자 키워드 상세(admin.keyword-browse.detail)가 이 스냅샷을 읽어 보여준다.
 */
class ExtKeywordShopSerpController extends Controller
{
    /**
     * 수집 대기열 — 아직 수집 안 했거나 오래된 쇼핑 키워드를 검색량 큰 순으로 준다.
     * 확장이 이 목록을 받아 한 건씩 연속 수집한다(수만 개를 사람이 클릭할 수 없으므로).
     */
    public function queue(Request $request)
    {
        $limit = min(50, max(1, (int) $request->query('limit', 20)));
        // 최근 이 기간 안에 수집한 키워드는 다시 주지 않는다(같은 키워드가 반복 수집되는 것 방지).
        // 기본 1일 — 순위는 매일 바뀔 수 있으니 하루 지난 것부터 재수집 대상.
        $days = max(1, (int) $request->query('days', 1));

        // 쇼핑 카테고리에 속한 후보 중, 스냅샷이 없거나 오래된 것
        $shopCatIds = \App\Models\KeywordCategory::where('type', 'shopping')->pluck('id');
        $fresh = KeywordShopSerp::where('collected_at', '>=', now()->subDays($days))->pluck('keyword');

        $keywords = \App\Models\KeywordCandidate::whereIn('category_id', $shopCatIds)
            ->whereNotIn('keyword', $fresh)
            ->orderByRaw('monthly_total is null')          // 검색량 있는 것 우선
            ->orderByDesc('monthly_total')
            ->limit($limit)
            ->pluck('keyword')
            ->values();

        return response()->json(['data' => [
            'keywords' => $keywords,
            'remaining' => \App\Models\KeywordCandidate::whereIn('category_id', $shopCatIds)
                ->whereNotIn('keyword', $fresh)->count(),
        ]]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'keyword' => 'required|string|max:120',
            'total' => 'nullable|integer|min:0',
            'products' => 'required|array|min:1|max:200',
            'products.*.title' => 'required|string|max:300',
            'products.*.rank' => 'nullable|integer|min:0',
            'products.*.price' => 'nullable|integer|min:0',
            'products.*.mallName' => 'nullable|string|max:120',
            'products.*.link' => 'nullable|string|max:1000',
            'products.*.isAd' => 'nullable|boolean',
            'related_tags' => 'nullable|array|max:50',
        ]);

        // 저장은 상위 80개까지 — 화면 목적상 그 이상은 불필요
        $items = array_slice(array_values($data['products']), 0, 80);

        $row = KeywordShopSerp::updateOrCreate(
            ['keyword' => trim($data['keyword'])],
            [
                'total' => (int) ($data['total'] ?? 0),
                'item_count' => count($items),
                'items' => $items,
                'related_tags' => $data['related_tags'] ?? null,
                'collected_at' => now(),
            ],
        );

        return response()->json(['data' => ['saved' => $row->item_count, 'collected_at' => $row->collected_at->toDateTimeString()]]);
    }
}
