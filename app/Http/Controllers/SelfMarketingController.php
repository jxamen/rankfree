<?php

namespace App\Http\Controllers;

use App\Models\MarketingProduct;
use App\Models\ProductType;
use Illuminate\Http\Request;

/**
 * 셀프마케팅 — 관리자가 등록한 마케팅 상품을 카드로 노출(비회원 열람 가능, 주문은 로그인).
 * 무료 분석 → 셀프 마케팅 상품 구매 퍼널의 상품 카탈로그. 카테고리(유형)·상품명 필터.
 */
class SelfMarketingController extends Controller
{
    public function index(Request $request)
    {
        $type = $request->query('type');
        $q = trim((string) $request->query('q', ''));
        $view = $request->query('view') === 'list' ? 'list' : 'card';

        $products = MarketingProduct::where('is_active', true)
            ->when($type, fn ($query) => $query->where('product_type', $type))
            ->when($q !== '', fn ($query) => $query->where('title', 'like', "%{$q}%"))
            ->orderBy('product_type')->orderBy('id')
            ->get();

        // 유형 코드 → 이름 맵
        $typeNames = ProductType::orderBy('sort_order')->pluck('name', 'code');
        // 상품이 실제로 있는 유형 코드(카테고리 칩용)
        $activeTypeCodes = MarketingProduct::where('is_active', true)->distinct()->pluck('product_type');

        return view('self-marketing.index', [
            'products' => $products,
            'typeNames' => $typeNames,
            'activeTypeCodes' => $activeTypeCodes,
            'type' => $type,
            'q' => $q,
            'view' => $view,
        ]);
    }
}
