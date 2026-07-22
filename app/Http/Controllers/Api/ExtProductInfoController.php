<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopProductInfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 확장 상품정보 수신(25) — 상품 페이지 simpleProductForDetailPage.A 에서 뽑은
 * 제목·업체명·가격·SEO태그·브랜드를 저장. 쇼핑 노출 키워드 분석이 조합 재료로 조회한다.
 */
class ExtProductInfoController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel_product_id' => 'required|string|max:40',
            'title' => 'nullable|string|max:300',
            'brand' => 'nullable|string|max:120',
            'mall_name' => 'nullable|string|max:150',
            'price' => 'nullable|integer|min:0|max:2000000000',
            'seller_tags' => 'nullable|array|max:60',
            'seller_tags.*' => 'nullable|string|max:80',
            'category' => 'nullable|string|max:191',
            'thumbnail_url' => 'nullable|string|max:500',   // 대표이미지 — 쇼핑 유입 발주용(2026-07-22)
        ]);

        $tags = array_values(array_unique(array_filter(array_map(
            fn ($s) => trim((string) $s), (array) ($data['seller_tags'] ?? [])
        ))));

        $row = ShopProductInfo::updateOrCreate(
            ['user_id' => $request->user()?->id, 'channel_product_id' => $data['channel_product_id']],
            [
                'title' => $data['title'] ?? null,
                'brand' => $data['brand'] ?? null,
                'mall_name' => $data['mall_name'] ?? null,
                'price' => $data['price'] ?? null,
                'seller_tags' => $tags,
                'category' => $data['category'] ?? null,
                'thumbnail_url' => $data['thumbnail_url'] ?? null,
                'collected_at' => now(),
            ],
        );

        return response()->json(['ok' => true, 'id' => $row->id]);
    }
}
