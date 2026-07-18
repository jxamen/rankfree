<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopSellerInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 판매자정보(관리자) — 캡차 통과 후 수집된 사업자 정보만 별도로 본다(수집 상품과 분리).
 * 컬럼: 업체명·대표자명·톡톡아이디·전화번호·스토어명(링크).
 *
 * 톡톡아이디·스토어명은 shop_seller_infos 에 없어 shop_products 를 store_id 로 조인해 보강한다
 * (한 스토어에 상품이 여럿이라 store_id 당 대표 1건: talk_id·링크 있는 행 우선).
 */
class SellerInfoController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $like = fn (string $s) => '%'.addcslashes($s, '\\%_').'%';

        $items = ShopSellerInfo::query()
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w
                ->where('biz_name', 'like', $like($q))
                ->orWhere('representative', 'like', $like($q))
                ->orWhere('customer_phone', 'like', $like($q))
                ->orWhere('store_id', 'like', $like($q))))
            ->latest('captured_at')->latest('id')
            ->paginate(100)->withQueryString();

        // 보이는 행의 store_id 로 상품 마스터에서 톡톡·스토어명·홈링크 보강
        $storeIds = collect($items->items())->pluck('store_id')->filter()->unique()->values()->all();
        $prodMap = collect();
        if ($storeIds) {
            $prodMap = DB::table('shop_products')
                ->whereIn('store_id', $storeIds)
                ->get(['store_id', 'talk_id', 'mall_name', 'link'])
                ->groupBy('store_id')
                ->map(function ($g) {
                    $withTalk = $g->first(fn ($r) => ! empty($r->talk_id));
                    $withLink = $g->first(fn ($r) => ! empty($r->link));
                    $link = $withLink->link ?? null;
                    // 상품 링크에서 스토어 홈 도출(smartstore/brand 구분 유지). 없으면 스마트스토어 기본.
                    $home = $link ? preg_replace('#/products/.*$#', '', $link) : null;

                    return (object) [
                        'talk_id' => $withTalk->talk_id ?? null,
                        'mall_name' => $withLink->mall_name ?? $g->first()->mall_name ?? null,
                        'home' => $home,
                    ];
                });
        }

        return view('admin.seller-infos', [
            'items' => $items,
            'prodMap' => $prodMap,
            'q' => $q,
            'total' => $items->total(),
        ]);
    }
}
