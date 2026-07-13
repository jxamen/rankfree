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

    public function show(MarketingOrder $order)
    {
        return view('admin.orders.show', [
            'order' => $order->load('product.fields', 'user'),
            'statuses' => MarketingOrder::STATUSES,
        ]);
    }

    public function updateStatus(Request $request, MarketingOrder $order)
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', array_keys(MarketingOrder::STATUSES))],
        ]);
        $order->update($data);

        return back()->with('status', "주문 {$order->order_no} 상태를 '".MarketingOrder::STATUSES[$data['status']]."'(으)로 변경했습니다.");
    }

    public function destroy(MarketingOrder $order)
    {
        $no = $order->order_no;
        $order->delete();

        return redirect()->route('admin.orders')->with('status', "주문 {$no} 을(를) 삭제했습니다.");
    }
}
