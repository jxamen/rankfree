<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopSellerCaptcha;
use App\Models\ShopSellerInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 수집 상품(관리자) — 키워드와 무관하게 지금까지 수집된 상품 전체를 한 곳에서 본다.
 * 키워드 상세(admin.keyword-browse.detail)가 '이 키워드에 노출된 상품'이라면, 여기는 상품이 주인공이다.
 *
 * 상품은 마스터(shop_products)에 1건만 있고 키워드↔상품은 월별 매핑(keyword_shop_ranks)이라,
 * 한 상품이 몇 개 키워드에 걸렸는지·어떤 키워드에서 몇 위인지를 매핑에서 집계한다.
 * 저장 대상은 스마트스토어 공개 노출분(상품명·가격·판매처·톡톡·순위)뿐 — 판매자 개인정보는 다루지 않는다.
 */
class ShopProductController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $mall = trim((string) $request->query('mall', ''));
        $ad = (string) $request->query('ad', '');          // ''=전체 · 'y'=광고만 · 'n'=광고 제외
        $talk = (string) $request->query('talk', '');      // 'y'=톡톡 있는 것만
        $month = (int) $request->query('month', 0);        // 수집 월(YYYYMM)
        $sort = in_array($request->query('sort'), ['recent', 'kw', 'price_high', 'price_low', 'title'], true)
            ? $request->query('sort') : 'recent';

        $like = fn (string $s) => '%'.addcslashes($s, '\\%_').'%';

        // Month 0 is the cross-month DISTINCT summary, maintained outside list requests.
        $base = fn () => DB::table('shop_product_summaries as p')
            ->where('p.collected_month', $month)
            // Substring search must scan: keep that scan sequential, not random sort-index lookups.
            ->when($q !== '' && DB::connection()->getDriverName() === 'mysql', fn ($x) => $x->forceIndex('PRIMARY'))
            ->when($q !== '', fn ($x) => $x->where('p.title', 'like', $like($q)))
            ->when($mall !== '', fn ($x) => $x->where('p.mall_name', $mall))
            ->when($ad === 'y', fn ($x) => $x->where('p.is_ad', true))
            ->when($ad === 'n', fn ($x) => $x->where('p.is_ad', false))
            ->when($talk === 'y', fn ($x) => $x->where('p.has_talk', true));

        $items = $base()
            ->select('p.product_key', 'p.title', 'p.price', 'p.mall_name', 'p.is_ad',
                'p.kw_cnt', 'p.best_rnk', 'p.last_at')
            ->when($sort === 'recent', fn ($x) => $x->orderByDesc('p.last_at'))
            ->when($sort === 'kw', fn ($x) => $x->orderByDesc('p.kw_cnt')->orderByDesc('p.last_at'))
            ->when($sort === 'price_high', fn ($x) => $x->orderByDesc('p.price'))
            ->when($sort === 'price_low', fn ($x) => $x->orderBy('p.price'))
            ->when($sort === 'title', fn ($x) => $x->orderBy('p.title'))
            ->orderBy('p.product_key', in_array($sort, ['recent', 'kw', 'price_high']) ? 'desc' : 'asc')
            ->simplePaginate(100)->withQueryString();

        // Fetch large/detail columns only for the visible page.
        $details = DB::table('shop_products')->whereIn('product_key', collect($items->items())->pluck('product_key'))
            ->get(['product_key', 'store_id', 'talk_id', 'link'])->keyBy('product_key');
        foreach ($items as $item) {
            $detail = $details->get($item->product_key);
            $item->store_id = $detail?->store_id;
            $item->talk_id = $detail?->talk_id;
            $item->link = $detail?->link;
        }

        // 어떤 키워드로 걸렸는지 — 보이는 상품 것만(목록당 100개라 가볍다)
        $keys = collect($items->items())->pluck('product_key')->all();
        $kwMap = [];
        if ($keys) {
            foreach (DB::table('keyword_shop_ranks')
                ->whereIn('product_key', $keys)
                ->when($month, fn ($x) => $x->where('collected_month', $month))
                ->orderBy('rnk')
                ->get(['product_key', 'keyword', 'rnk']) as $r) {
                $kwMap[$r->product_key][] = ['keyword' => $r->keyword, 'rnk' => $r->rnk];
            }
        }

        // 퀴즈(판매자정보 캡차)로 수집된 정보 — 보이는 상품의 store_id 기준으로 묶는다.
        // store_id 는 DB 값 우선, 없으면 상품 링크(스마트스토어/브랜드스토어)에서 추출(뷰와 동일 규칙).
        $storeIds = [];
        foreach ($items->items() as $p) {
            $sid = $p->store_id ?: '';
            if ($sid === '' && ! empty($p->link) && preg_match('#(?:smartstore|brand)\.naver\.com/([^/]+)/#', $p->link, $mm)) {
                $sid = $mm[1];
            }
            if ($sid !== '') {
                $storeIds[] = $sid;
            }
        }
        $sellerInfoMap = collect();
        if ($storeIds) {
            $sellerInfoMap = ShopSellerInfo::whereIn('store_id', array_values(array_unique($storeIds)))
                ->orderByDesc('captured_at')->orderByDesc('id')->get()
                ->groupBy('store_id')->map(fn ($g) => $g->first());
        }

        return view('admin.shop-products', [
            'items' => $items,
            'kwMap' => $kwMap,
            'sellerInfoMap' => $sellerInfoMap,
            'q' => $q, 'mall' => $mall, 'ad' => $ad, 'talk' => $talk, 'month' => $month, 'sort' => $sort,
            // 필터 후보 — 판매처가 많아질 수 있어 상위만(캐시)
            'malls' => Cache::flexible('shop_products:malls', [300, 86400], fn () => DB::table('shop_products')
                ->whereNotNull('mall_name')->selectRaw('mall_name, count(*) as c')
                ->groupBy('mall_name')->orderByDesc('c')->limit(50)->pluck('c', 'mall_name')->all()),
            'months' => Cache::flexible('shop_products:months', [300, 86400], fn () => DB::table('shop_product_summaries')->where('collected_month', '>', 0)
                ->distinct()->orderByDesc('collected_month')->pluck('collected_month')->all()),
        ]);
    }

    public function captchaImage(ShopSellerCaptcha $captcha)
    {
        $disk = $captcha->image_disk ?: 'local';
        abort_unless($captcha->image_path && Storage::disk($disk)->exists($captcha->image_path), 404);

        $mime = $captcha->image_mime ?: 'application/octet-stream';
        $name = ($captcha->captcha_key ?: 'seller-captcha').'.'.match ($mime) {
            'image/png' => 'png',
            'image/jpeg', 'image/pjpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'bin',
        };

        return response(Storage::disk($disk)->get($captcha->image_path), 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
