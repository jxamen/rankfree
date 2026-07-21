{{-- 쿠폰 선택 — 사용 가능 쿠폰이 있을 때만 노출. 할인액은 JS 미리보기 + 서버 재계산(discountFor). --}}
@if (($coupons ?? collect())->isNotEmpty())
    <div class="mb-3">
        <label class="block text-muted" style="font-size:var(--fs-xs);font-weight:600;">쿠폰 <span class="text-muted-soft">(보유 {{ $coupons->count() }}장)</span></label>
        <select name="user_coupon_id" id="o-coupon" class="input mt-1" style="width:100%;max-width:420px;">
            <option value="">사용 안 함</option>
            @foreach ($coupons as $uc)
                <option value="{{ $uc->id }}"
                    data-type="{{ $uc->coupon->discount_type }}"
                    data-value="{{ (float) $uc->coupon->discount_value }}"
                    data-max="{{ $uc->coupon->max_discount !== null ? (float) $uc->coupon->max_discount : '' }}"
                    data-min="{{ (float) $uc->coupon->min_order_amount }}"
                    @selected(old('user_coupon_id') == $uc->id)>
                    {{ $uc->coupon->name }} — {{ $uc->coupon->discountLabel() }}@if ((float) $uc->coupon->min_order_amount > 0) · {{ number_format((float) $uc->coupon->min_order_amount) }}원 이상@endif
                </option>
            @endforeach
        </select>
        <div id="o-coupon-note" class="mt-1" style="font-size:var(--fs-xs);color:var(--color-error);display:none;">최소 주문 금액 미달 — 이 금액에는 쿠폰이 적용되지 않습니다.</div>
    </div>
@endif
