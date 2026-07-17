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
