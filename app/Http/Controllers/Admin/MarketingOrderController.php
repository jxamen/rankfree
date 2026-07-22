<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketingOrder;
use App\Models\MarketingProduct;
use Illuminate\Http\Request;

/** 마케팅 상품 주문 관리(admin) — 목록·필터·상세·상태 변경. */
class MarketingOrderController extends Controller
{
    public function index(Request $request)
    {
        $q = MarketingOrder::with('product', 'user')
            ->with('shopKeywordAnalyses:id,marketing_order_id,exposed_count,status')   // 유입키워드 연결 표시용
            ->latest();

        if (($status = $request->query('status')) && isset(MarketingOrder::STATUSES[$status])) {
            $q->where('status', $status);
        }
        if ($pid = $request->query('product')) {
            $q->where('product_id', $pid);
        }
        if ($kw = trim((string) $request->query('q', ''))) {
            $q->where(function ($w) use ($kw) {
                $w->where('order_no', 'like', "%{$kw}%")
                    ->orWhere('orderer_name', 'like', "%{$kw}%")
                    ->orWhere('orderer_contact', 'like', "%{$kw}%");
            });
        }

        return view('admin.orders.index', [
            'orders' => $q->paginate(20)->withQueryString(),
            'products' => MarketingProduct::orderBy('title')->get(['id', 'title']),
            'statuses' => MarketingOrder::STATUSES,
            'counts' => MarketingOrder::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
            'filters' => ['status' => $request->query('status'), 'product' => $request->query('product'), 'q' => $request->query('q')],
        ]);
    }

    /**
     * 쇼핑 유입키워드 수집 요청 — 주문 입력값(keyword·shop_url)으로 노출 키워드 분석을 만들어
     * 주문과 상호 연결한다(2026-07-22). 발주 시 분석의 Short URL 을 쓰는 흐름의 진입점.
     */
    public function createShopKeyword(Request $request, MarketingOrder $order, \App\Domain\Shopping\ShopKeywordExposureAnalyzer $analyzer)
    {
        // 이미 연결된 분석이 있으면 그리로 — 중복 생성 방지(멱등)
        if ($existing = $order->shopKeywordAnalyses()->latest('id')->first()) {
            return redirect()->route('admin.shop-keyword.show', $existing)
                ->with('status', "주문 {$order->order_no} 에 연결된 분석으로 이동했습니다.");
        }

        $src = $order->shopKeywordSource();
        if (! $src) {
            return back()->withErrors(['shop_keyword' => '이 주문에서 키워드·상품 URL 을 찾지 못했습니다 — 쇼핑 유입 주문이 아니거나 입력값이 비어 있습니다.']);
        }

        $analysis = $analyzer->prepare($request->user(), $src['keyword'], $src['url']);
        $analysis->update(['marketing_order_id' => $order->id]);
        app(\App\Domain\Order\OrderFieldAutofill::class)->fillFromAnalysis($analysis);   // 이미 수집된 값이 있으면 즉시 채움

        return redirect()->route('admin.shop-keyword.show', $analysis)
            ->with('status', "주문 {$order->order_no} 의 유입키워드 수집을 시작했습니다 — 노출 키워드가 모이면 Short URL 을 생성해 발주에 쓰세요.");
    }

    public function show(MarketingOrder $order, \App\Domain\Order\OrderDispatchService $dispatcher)
    {
        $order->load('product.fields', 'product.vendorAllocations.vendor', 'user', 'dispatches', 'shopKeywordAnalyses.shortLinks');

        // 승인 전 배분 미리보기 — 활성 업체 배분에 이 주문 수량을 적용한 결과
        $allocRows = $order->product
            ? $order->product->vendorAllocations->where('is_active', true)->filter(fn ($pv) => $pv->vendor && $pv->vendor->is_active)->values()
            : collect();
        $allocPreview = $allocRows->isNotEmpty() ? $dispatcher->allocate($order->quantity, $allocRows) : [];

        return view('admin.orders.show', [
            'order' => $order,
            'statuses' => MarketingOrder::STATUSES,
            'allocPreview' => $allocPreview,
        ]);
    }

    /** 내부(숨김) 필드 값 수동 저장 — 수집이 안 채운 항목을 관리자가 직접 입력. */
    public function updateInternalFields(Request $request, MarketingOrder $order)
    {
        $order->loadMissing('product.fields');
        $hidden = $order->product?->fields->where('is_active', true)->where('is_hidden', true) ?? collect();
        if ($hidden->isEmpty()) {
            return back()->withErrors(['internal' => '이 상품에는 내부(숨김) 필드가 없습니다.']);
        }

        $fv = (array) $order->field_values;
        $input = (array) $request->input('internal', []);
        foreach ($hidden as $f) {
            if (array_key_exists($f->field_key, $input)) {
                $v = trim((string) $input[$f->field_key]);
                $fv[$f->field_key] = $v !== '' ? $v : null;
            }
        }
        $order->update(['field_values' => $fv]);

        return back()->with('status', '내부 필드 값을 저장했습니다.');
    }

    /** 연결된 유입키워드 분석의 수집값으로 내부 필드 다시 채우기(기존 값 덮어씀). */
    public function autofillInternalFields(MarketingOrder $order, \App\Domain\Order\OrderFieldAutofill $autofill)
    {
        $analysis = $order->shopKeywordAnalyses()->latest('id')->first();
        if (! $analysis) {
            return back()->withErrors(['internal' => '연결된 유입키워드 분석이 없습니다 — 먼저 수집요청을 하세요.']);
        }

        $filled = $autofill->fillFromAnalysis($analysis, force: true);

        return back()->with('status', $filled > 0
            ? "수집값으로 {$filled}개 필드를 채웠습니다."
            : '채울 수 있는 수집값이 아직 없습니다 — 확장 상품정보 수집 후 다시 시도하세요.');
    }

    /** 승인 — 상품의 업체 배분 설정대로 외부 발주(API/구글시트) 후 진행중으로 전환. */
    public function approve(MarketingOrder $order, \App\Domain\Order\OrderDispatchService $dispatcher)
    {
        // 필수 내부(숨김) 필드가 비어 있으면 발주 차단 — 수집·수동 입력으로 채운 뒤 승인
        $order->loadMissing('product.fields');
        $fv = (array) $order->field_values;
        $missing = ($order->product?->fields ?? collect())
            ->where('is_active', true)->where('is_hidden', true)->where('is_required', true)
            ->filter(fn ($f) => trim((string) ($fv[$f->field_key] ?? '')) === '')
            ->pluck('label');
        if ($missing->isNotEmpty()) {
            return back()->withErrors(['approve' => '필수 내부 필드가 비어 있어 승인할 수 없습니다: '.$missing->implode(', ').' — 수집값 채우기 또는 직접 입력 후 승인하세요.']);
        }

        $result = $dispatcher->dispatch($order);
        if (! $result['ok']) {
            return back()->withErrors(['approve' => $result['message']]);
        }
        if ($order->status === 'pending') {
            $order->update(['status' => 'processing']);
        }

        return back()->with('status', "주문 {$order->order_no} 승인 — ".$result['message']);
    }

    /** 실패한 업체 전송 건 재전송. */
    public function retryDispatch(\App\Models\OrderDispatch $dispatch, \App\Domain\Order\OrderDispatchService $dispatcher)
    {
        $d = $dispatcher->retry($dispatch);

        return back()->with('status', "'{$d->vendor_name}' 재전송 — ".\App\Models\OrderDispatch::STATUSES[$d->status]);
    }

    public function updateStatus(Request $request, MarketingOrder $order)
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', array_keys(MarketingOrder::STATUSES))],
        ]);
        // 취소 전환 시 쿠폰 복원(취소를 다시 되돌려도 쿠폰은 재사용되지 않음 — 필요하면 재발행)
        if ($data['status'] === 'canceled' && $order->status !== 'canceled') {
            $order->restoreCoupon();
        }
        $order->update($data);

        return back()->with('status', "주문 {$order->order_no} 상태를 '".MarketingOrder::STATUSES[$data['status']]."'(으)로 변경했습니다.");
    }

    public function destroy(MarketingOrder $order)
    {
        $no = $order->order_no;
        $order->restoreCoupon();   // 삭제 주문에 묶인 쿠폰은 되돌려준다
        $order->delete();

        return redirect()->route('admin.orders')->with('status', "주문 {$no} 을(를) 삭제했습니다.");
    }
}
