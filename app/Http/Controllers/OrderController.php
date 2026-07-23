<?php

namespace App\Http\Controllers;

use App\Domain\Place\PlaceRankChecker;
use App\Models\MarketingProduct;
use App\Models\ProductType;
use App\Models\UserCoupon;
use Illuminate\Http\Request;

/** 마케팅 상품 주문 — 콘솔 내 회원 전용 주문 페이지(order_token 기반). */
class OrderController extends Controller
{
    public function show(Request $request, string $token)
    {
        $product = MarketingProduct::where('order_token', $token)->where('is_active', true)->firstOrFail();
        $product->load('fields', 'fieldGroups');
        $sched = $this->scheduleFields($product);
        $specialKeys = collect($sched)->filter()->pluck('field_key')->all();

        // 숨김(내부) 필드는 고객 폼에 렌더하지 않는다 — 발주 전달용 값은 유입키워드 수집으로 채운다(2026-07-22)
        $infoFields = $product->fields->where('is_active', true)->where('is_hidden', false)
            ->reject(fn ($f) => in_array($f->field_key, $specialKeys, true))->values();

        // 스텝 모드: 정보 필드를 그룹별 단계로 묶는다(그룹 정렬 순).
        $groupOrder = $product->fieldGroups->sortBy('sort_order')->values();
        $infoGroups = $infoFields
            ->groupBy('group_id')
            ->map(fn ($fields, $gid) => [
                'name' => $groupOrder->firstWhere('id', $gid)->name ?? '주문 정보',
                'sort' => $groupOrder->firstWhere('id', $gid)->sort_order ?? 999,
                'fields' => $fields->values(),
            ])
            ->sortBy('sort')->values();

        return view('order.show', [
            'product' => $product,
            // 셀프마케팅 카탈로그와 동일한 유형 탭(전체·블로그 리뷰·체험단…) 노출용
            'typeNames' => ProductType::orderBy('sort_order')->pluck('name', 'code'),
            'activeTypeCodes' => MarketingProduct::where('is_active', true)->distinct()->pluck('product_type'),
            'minDate' => $product->earliestStartDate()->toDateString(),
            'qtyField' => $sched['qty'],
            'startField' => $sched['start'],
            'endField' => $sched['end'],
            'infoFields' => $infoFields,
            'infoGroups' => $infoGroups,
            'stepMode' => $product->field_render_mode === 'step',
            // 이 상품에 지금 쓸 수 있는 쿠폰(미사용·미만료·활성·기간 내·상품 적용 가능)
            'coupons' => $request->user()->usableCoupons()
                ->filter(fn (UserCoupon $uc) => $uc->coupon->appliesTo($product->id))->values(),
            // 이 상품에 대한 내 주문 접수 내역(최근 20건 + 전체 건수) — 순위 표기용 슬롯 포함
            'myOrders' => $product->orders()->where('user_id', $request->user()->id)->with('shopRankSlot')->latest()->limit(20)->get(),
            'myOrdersTotal' => $product->orders()->where('user_id', $request->user()->id)->count(),
        ]);
    }

    public function store(Request $request, string $token, \App\Domain\Order\OrderPlacer $placer)
    {
        $product = MarketingProduct::where('order_token', $token)->where('is_active', true)->firstOrFail();
        $product->load('fields');

        // 웹 전용: 파일 업로드 저장 후 나머지 입력과 합쳐 공용 로직(OrderPlacer)으로 — 검증·계산은 API 와 동일
        $input = [];
        foreach ($product->fields->where('is_active', true)->where('is_hidden', false) as $f) {
            $key = 'f_'.$f->field_key;
            if (in_array($f->field_type, ['FILE', 'IMAGE'], true)) {
                $input[$f->field_key] = $request->hasFile($key)
                    ? $request->file($key)->store('orders/'.$product->id, 'public')
                    : null;
            } else {
                $input[$f->field_key] = $request->input($key);
            }
        }

        try {
            $order = $placer->place($request->user(), $product, $input, [
                'quantity' => $request->input('quantity'),
                'days' => $request->input('days'),
                'user_coupon_id' => $request->input('user_coupon_id'),
            ]);
        } catch (\App\Domain\Order\OrderInputException $e) {
            return back()->withInput()->withErrors([$e->field => $e->getMessage()]);
        }

        return redirect()->route('order.show', $token)->with('order_done', $order->order_no);
    }

    /** 붙여넣은 플레이스 URL → 실제 업종별 m.place URL 로 정규화(주문 폼 즉시 반영용). */
    public function resolvePlace(Request $request)
    {
        $url = (string) $request->input('url', '');
        try {
            $clean = app(PlaceRankChecker::class)->cleanPlaceUrl($url);
        } catch (\Throwable $e) {
            $clean = null;
        }

        return response()->json(['url' => $clean]);
    }

    /**
     * 상품의 수량·기간 시스템 필드(일수량·시작일·종료일)를 field_key 로 식별.
     * 없으면 null → 주문 페이지가 기본 수량/기간 입력으로 폴백한다.
     *
     * @return array{qty:?\App\Models\ProductField, start:?\App\Models\ProductField, end:?\App\Models\ProductField}
     */
    private function scheduleFields(MarketingProduct $product): array
    {
        $active = $product->fields->where('is_active', true);

        return [
            'qty' => $active->firstWhere('field_key', 'daily_qty'),
            'start' => $active->firstWhere('field_key', 'start_date'),
            'end' => $active->firstWhere('field_key', 'end_date'),
        ];
    }
}
