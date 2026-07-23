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
        $q = MarketingOrder::with('product.fields', 'user')
            // 유입키워드 연결 + 수집 정보(상점명·가격·상품ID) 표시용
            ->with('shopKeywordAnalyses:id,marketing_order_id,user_id,product_id,product_url,mall_name,product_price,exposed_count,status')
            // 발주 취소 버튼 노출용 — 활성(미취소) 발주 수
            ->withCount(['dispatches as active_dispatch_count' => fn ($q) => $q->where('status', '!=', 'canceled')])
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

        $orders = $q->paginate(20)->withQueryString();

        // 수집 정보(대표이미지·제목 폴백) 일괄 조회 — 행별 N+1 방지. 키 = "user|channel_product_id"
        $analyses = $orders->getCollection()->flatMap->shopKeywordAnalyses->filter(fn ($a) => $a->product_id);
        $productInfos = $analyses->isEmpty() ? collect() : \App\Models\ShopProductInfo::query()
            ->whereIn('user_id', $analyses->pluck('user_id')->unique())
            ->whereIn('channel_product_id', $analyses->pluck('product_id')->unique())
            ->get()->keyBy(fn ($i) => $i->user_id.'|'.$i->channel_product_id);

        return view('admin.orders.index', [
            'orders' => $orders,
            'productInfos' => $productInfos,
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
        $order->load('product.fields', 'product.vendorAllocations.vendor', 'user', 'dispatches', 'shopKeywordAnalyses.shortLinks', 'items.vendor');

        // 승인 전 배분 미리보기 — 활성 업체 배분에 이 주문 수량을 적용한 결과
        $allocRows = $order->product
            ? $order->product->vendorAllocations->where('is_active', true)->filter(fn ($pv) => $pv->vendor && $pv->vendor->is_active)->values()
            : collect();
        $allocPreview = $allocRows->isNotEmpty() ? $dispatcher->allocate($order->quantity, $allocRows) : [];

        return view('admin.orders.show', [
            'order' => $order,
            'statuses' => MarketingOrder::STATUSES,
            'allocPreview' => $allocPreview,
            // 세부주문서 업체 셀렉트 옵션 — 이 상품 배분에 등록된 활성 업체
            'itemVendors' => $allocRows->pluck('vendor')->filter()->unique('id')->values(),
        ]);
    }

    /** 세부주문서 수동 생성 — 기간형인데 아직 없는 기존 주문용(예: 세부주문 도입 전 접수분). */
    public function generateItems(MarketingOrder $order, \App\Domain\Order\OrderItemPlanner $planner)
    {
        $n = $planner->generate($order);
        if ($n < 1) {
            return back()->withErrors(['items' => '세부주문을 만들 수 없습니다 — 기간형(일수량×기간) 주문이 아니거나 이미 생성돼 있습니다.']);
        }

        return back()->with('status', "세부주문 {$n}건을 생성했습니다(업체 분산·Short URL 순차 배정 포함).");
    }

    /** 세부주문 일괄 수정 — 회차별 업체·Short URL(수동 교체). */
    public function updateItems(Request $request, MarketingOrder $order)
    {
        $input = (array) $request->input('items', []);
        $vendorIds = $order->product?->vendorAllocations()->where('is_active', true)->pluck('vendor_id')->all() ?? [];
        $n = 0;
        foreach ($order->items as $item) {
            if (! isset($input[$item->id])) {
                continue;
            }
            $row = (array) $input[$item->id];
            $upd = [];
            if (array_key_exists('short_url', $row)) {
                $upd['short_url'] = trim((string) $row['short_url']) ?: null;
            }
            if (array_key_exists('vendor_id', $row)) {
                $vid = (int) $row['vendor_id'];
                $upd['vendor_id'] = $vid && in_array($vid, $vendorIds, true) ? $vid : null;
            }
            if ($upd) {
                $item->update($upd);
                $n++;
            }
        }

        return back()->with('status', "세부주문 {$n}건을 저장했습니다.");
    }

    /** 세부주문 개별 발주 — 진행일과 무관하게 관리자가 수동 전송(실패·취소 회차 재발주 포함). */
    public function dispatchItem(\App\Models\MarketingOrderItem $item, \App\Domain\Order\OrderDispatchService $dispatcher, \App\Domain\Order\OrderItemPlanner $planner)
    {
        if ($item->status === 'sent') {
            return back()->withErrors(['items' => "{$item->day_no}일차는 이미 전송됐습니다 — 다시 보내려면 먼저 취소하세요."]);
        }
        $order = $item->order;
        if ($order && $planner->mappingUsesShortUrl($order->product) && trim((string) $item->short_url) === '') {
            return back()->withErrors(['items' => "{$item->day_no}일차: Short URL 이 배정되지 않아 발주할 수 없습니다."]);
        }
        $r = $dispatcher->dispatchItem($item);

        return $r['ok'] ? back()->with('status', $r['message']) : back()->withErrors(['items' => $r['message']]);
    }

    /** 세부주문 취소 — 회차 취소 + 연결 전송 기록도 취소 표시(시트 행은 수동 정리). */
    public function cancelItem(\App\Models\MarketingOrderItem $item)
    {
        if ($item->status === 'canceled') {
            return back()->withErrors(['items' => '이미 취소된 세부주문입니다.']);
        }
        $item->update(['status' => 'canceled']);
        if ($item->dispatch && $item->dispatch->status !== 'canceled') {
            $item->dispatch->update(['status' => 'canceled']);
        }

        return back()->with('status', "{$item->day_no}일차 세부주문을 취소했습니다. 시트에 이미 적힌 행은 직접 정리하세요.");
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
    /**
     * 목록 '주문넣기'(2026-07-23) — 수집이 끝난 쇼핑 주문을 매핑된 업체로 바로 발주.
     * 발주 필수 값(활성 필수 필드 — 자동수집되는 숨김 필드 포함)이 비어 있으면 경고와 함께 차단한다.
     */
    public function placeVendorOrder(MarketingOrder $order, \App\Domain\Order\OrderDispatchService $dispatcher)
    {
        if ($order->status !== 'pending') {
            return back()->withErrors(['place' => "주문 {$order->order_no}: 접수 상태에서만 발주할 수 있습니다(현재 ".(MarketingOrder::STATUSES[$order->status] ?? $order->status).')']);
        }
        if (! $order->product) {
            return back()->withErrors(['place' => "주문 {$order->order_no}: 상품 정보가 없어 발주할 수 없습니다."]);
        }

        // 필수값 검증 — 활성 필수 필드 중 field_values 가 빈 항목이 하나라도 있으면 발주하지 않는다
        $fv = (array) $order->field_values;
        $missing = $order->product->fields
            ->where('is_active', true)->where('is_required', true)
            ->filter(function ($f) use ($fv) {
                $v = $fv[$f->field_key] ?? null;

                return is_null($v) || $v === '' || $v === [];
            })
            ->pluck('label')->values();
        if ($missing->isNotEmpty()) {
            return back()->withErrors(['place' => "주문 {$order->order_no}: 필수 값이 비어 있어 발주할 수 없습니다 — [".$missing->join(', ').'] 수집이 끝나길 기다리거나 주문 상세에서 직접 채워주세요.']);
        }
        if ($err = $this->shortUrlGuard($order)) {
            return back()->withErrors(['place' => $err]);
        }

        $result = $order->items()->exists()
            ? $this->dispatchDueItems($order, $dispatcher)
            : $dispatcher->dispatch($order);
        if (! $result['ok']) {
            return back()->withErrors(['place' => "주문 {$order->order_no}: ".$result['message']]);
        }
        $order->update(['status' => 'processing']);

        return back()->with('status', "주문 {$order->order_no} 발주 완료 — ".$result['message']);
    }

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
        if ($err = $this->shortUrlGuard($order)) {
            return back()->withErrors(['approve' => $err]);
        }

        $result = $order->items()->exists()
            ? $this->dispatchDueItems($order, $dispatcher)
            : $dispatcher->dispatch($order);
        if (! $result['ok']) {
            return back()->withErrors(['approve' => $result['message']]);
        }
        if ($order->status === 'pending') {
            $order->update(['status' => 'processing']);
        }

        return back()->with('status', "주문 {$order->order_no} 승인 — ".$result['message']);
    }

    /**
     * 발주 전 Short URL 가드 — **업체 매핑이 Short URL 항목을 실제로 쓸 때만** 검사한다(2026-07-23 확정).
     * 세부주문이 있으면 회차별 배정 URL, 없으면 분석의 링크 존재 여부를 본다.
     */
    private function shortUrlGuard(MarketingOrder $order): ?string
    {
        $order->loadMissing('product.fields');
        if (! $order->product || ! app(\App\Domain\Order\OrderItemPlanner::class)->mappingUsesShortUrl($order->product)) {
            return null;   // 매핑이 Short URL 을 안 쓰는 상품 — 없어도 발주 가능
        }
        if ($order->items()->exists()) {
            $missing = $order->items()->where('status', '!=', 'canceled')
                ->where(fn ($q) => $q->whereNull('short_url')->orWhere('short_url', ''))->count();

            return $missing > 0
                ? "주문 {$order->order_no}: Short URL 미배정 세부주문 {$missing}건 — 분석에서 Short URL 생성(또는 세부주문에 직접 입력) 후 발주하세요."
                : null;
        }
        $analysis = $order->shopKeywordAnalyses()->latest('id')->first();
        if ($analysis && ! $analysis->shortLinks()->exists()) {
            return "주문 {$order->order_no}: Short URL 이 아직 없어 발주할 수 없습니다 — 유입키워드 분석에서 Short URL 을 먼저 생성하세요.";
        }

        return null;
    }

    /** 세부주문 주문의 승인 발주 — 진행일 도래분 즉시 전송, 나머지는 예약(스케줄러가 매일 발주). */
    private function dispatchDueItems(MarketingOrder $order, \App\Domain\Order\OrderDispatchService $dispatcher): array
    {
        $due = $order->items()->where('status', 'pending')->whereDate('work_date', '<=', today())->orderBy('day_no')->get();
        $future = $order->items()->where('status', 'pending')->whereDate('work_date', '>', today())->count();
        if ($due->isEmpty() && $future === 0) {
            return ['ok' => false, 'message' => '발주할 대기 세부주문이 없습니다(모두 전송·취소됨). 재발주는 회차별 [발주]를 쓰세요.'];
        }

        $sent = 0;
        $failedMsgs = [];
        foreach ($due as $item) {
            $r = $dispatcher->dispatchItem($item);
            $r['ok'] ? $sent++ : $failedMsgs[] = $r['message'];
        }
        $msg = "세부주문 발주 — 오늘까지분 {$due->count()}건(성공 {$sent}".($failedMsgs ? ' · '.implode(' / ', $failedMsgs) : '').')';
        if ($future > 0) {
            $msg .= " · 예약 {$future}건은 진행일 아침(09:00)에 자동 발주됩니다";
        }

        return ['ok' => true, 'message' => $msg];
    }

    /** 실패한 업체 전송 건 재전송. */
    public function retryDispatch(\App\Models\OrderDispatch $dispatch, \App\Domain\Order\OrderDispatchService $dispatcher)
    {
        $d = $dispatcher->retry($dispatch);

        return back()->with('status', "'{$d->vendor_name}' 재전송 — ".\App\Models\OrderDispatch::STATUSES[$d->status]);
    }

    /**
     * 발주 취소(개별) — 전송 기록만 취소로 표시한다. 시트에 이미 적힌 행은 지우지 않는다(수동 정리).
     * 활성 발주가 모두 없어지면 주문을 접수 상태로 되돌려 다시 발주할 수 있게 한다.
     */
    public function cancelDispatch(\App\Models\OrderDispatch $dispatch)
    {
        if ($dispatch->status === 'canceled') {
            return back()->withErrors(['dispatch' => '이미 취소된 발주입니다.']);
        }
        $dispatch->update(['status' => 'canceled']);
        // 연결 세부주문은 대기로 되돌려 재발주 가능하게
        \App\Models\MarketingOrderItem::where('dispatch_id', $dispatch->id)->where('status', '!=', 'canceled')
            ->update(['status' => 'pending']);

        $msg = "'{$dispatch->vendor_name}' 발주를 취소했습니다.";
        if ($dispatch->channel === 'gsheet' && $dispatch->getOriginal('status') !== 'failed') {
            $msg .= ' 시트에 추가된 행은 자동으로 지워지지 않으니 필요하면 직접 정리하세요.';
        }
        $order = $dispatch->order;
        if ($order && $order->status === 'processing' && ! $order->dispatches()->where('status', '!=', 'canceled')->exists()) {
            $order->update(['status' => 'pending']);
            $msg .= ' 활성 발주가 없어 접수 상태로 되돌렸습니다 — 다시 발주할 수 있습니다.';
        }

        return back()->with('status', $msg);
    }

    /** 발주 전체 취소(목록용) — 주문의 활성 발주를 모두 취소하고 접수 상태로 되돌린다(재발주용). */
    public function cancelDispatches(MarketingOrder $order)
    {
        $active = $order->dispatches()->where('status', '!=', 'canceled')->get();
        if ($active->isEmpty()) {
            return back()->withErrors(['dispatch' => "주문 {$order->order_no}: 취소할 발주가 없습니다."]);
        }
        foreach ($active as $d) {
            $d->update(['status' => 'canceled']);
        }
        // 전송·실패 세부주문은 대기로 되돌려 재발주 가능하게(취소 회차는 유지)
        $order->items()->whereIn('status', ['sent', 'failed'])->update(['status' => 'pending']);
        if ($order->status === 'processing') {
            $order->update(['status' => 'pending']);
        }

        return back()->with('status', "주문 {$order->order_no} 발주 {$active->count()}건을 취소하고 접수 상태로 되돌렸습니다."
            .' 시트에 추가된 행은 자동으로 지워지지 않으니 필요하면 직접 정리하세요. 이제 다시 발주할 수 있습니다.');
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
