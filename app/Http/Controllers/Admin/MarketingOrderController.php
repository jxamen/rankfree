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
        $q = MarketingOrder::with('product', 'user')->latest();

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

    public function show(MarketingOrder $order, \App\Domain\Order\OrderDispatchService $dispatcher)
    {
        $order->load('product.fields', 'product.vendorAllocations.vendor', 'user', 'dispatches');

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

    /** 승인 — 상품의 업체 배분 설정대로 외부 발주(API/구글시트) 후 진행중으로 전환. */
    public function approve(MarketingOrder $order, \App\Domain\Order\OrderDispatchService $dispatcher)
    {
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
