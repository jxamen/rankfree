{{-- 쿠폰 생성·수정 공용 폼 필드. $coupon=null 이면 생성. --}}
@php $isPercent = old('discount_type', $coupon->discount_type ?? 'amount') === 'percent'; @endphp
<div class="flex gap-3 items-end flex-wrap">
    <div><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">쿠폰명</label>
        <input name="name" class="input" style="width:220px;" value="{{ old('name', $coupon->name ?? '') }}" placeholder="예: 신규가입 5,000원 할인" required></div>
    <div><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">할인 유형</label>
        <select name="discount_type" class="input" style="width:110px;" onchange="var v=this.value;this.closest('form').querySelectorAll('[data-percent-only]').forEach(function(e){e.style.display=v==='percent'?'':'none';})">
            @foreach (\App\Models\Coupon::TYPES as $k => $label)
                <option value="{{ $k }}" @selected(old('discount_type', $coupon->discount_type ?? 'amount') === $k)>{{ $label }}</option>
            @endforeach
        </select></div>
    <div><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">할인 값 <span class="text-muted-soft">(원 또는 %)</span></label>
        <input type="number" name="discount_value" class="input" style="width:120px;" value="{{ old('discount_value', $coupon ? (float) $coupon->discount_value : '') }}" min="1" step="1" required></div>
    <div data-percent-only style="{{ $isPercent ? '' : 'display:none;' }}"><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">최대 할인액(원) <span class="text-muted-soft">(정률 전용)</span></label>
        <input type="number" name="max_discount" class="input" style="width:130px;" value="{{ old('max_discount', $coupon && $coupon->max_discount !== null ? (float) $coupon->max_discount : '') }}" min="1" step="1" placeholder="비우면 무제한"></div>
    <div><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">최소 주문 금액(원)</label>
        <input type="number" name="min_order_amount" class="input" style="width:130px;" value="{{ old('min_order_amount', $coupon ? (int) $coupon->min_order_amount : 0) }}" min="0" step="1"></div>
    <div style="flex-basis:100%;height:0;"></div>
    <div><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">사용 시작일</label>
        <input type="date" name="starts_at" class="input" style="width:150px;" value="{{ old('starts_at', $coupon?->starts_at?->format('Y-m-d')) }}"></div>
    <div><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">사용 종료일</label>
        <input type="date" name="ends_at" class="input" style="width:150px;" value="{{ old('ends_at', $coupon?->ends_at?->format('Y-m-d')) }}"></div>
    <div><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">발급일로부터 유효(일) <span class="text-muted-soft">(종료일과 중 이른 쪽)</span></label>
        <input type="number" name="valid_days" class="input" style="width:150px;" value="{{ old('valid_days', $coupon->valid_days ?? '') }}" min="1" placeholder="비우면 종료일까지"></div>
    <div><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">발급 수량 제한(매)</label>
        <input type="number" name="max_issuance" class="input" style="width:130px;" value="{{ old('max_issuance', $coupon->max_issuance ?? '') }}" min="1" placeholder="비우면 무제한"></div>
    <span class="flex items-center gap-2 text-muted" style="font-size:var(--fs-xs);height:40px;">
        <label class="rf-switch"><input type="checkbox" name="is_downloadable" value="1" @checked(old('is_downloadable', $coupon->is_downloadable ?? false))><span class="rf-track"></span></label> 쿠폰함 다운로드 허용
    </span>
    <span class="flex items-center gap-2 text-muted" style="font-size:var(--fs-xs);height:40px;">
        <label class="rf-switch"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $coupon->is_active ?? true))><span class="rf-track"></span></label> 활성
    </span>
    <div style="flex-basis:100%;">
        <label class="block text-muted mb-1" style="font-size:var(--fs-xs);font-weight:600;">적용 상품 <span class="text-muted-soft">(아무것도 선택 안 하면 전체 상품)</span></label>
        <div class="flex gap-3 flex-wrap">
            @forelse ($products as $p)
                <label class="flex items-center gap-1.5 text-body" style="font-size:var(--fs-xs);">
                    <input type="checkbox" name="product_ids[]" value="{{ $p->id }}"
                        @checked(in_array($p->id, array_map('intval', old('product_ids', $coupon->product_ids ?? [])), true))>
                    {{ $p->title }}
                </label>
            @empty
                <span class="text-muted-soft" style="font-size:var(--fs-xs);">등록된 마케팅 상품이 없습니다 — 전체 상품 기준으로 적용됩니다.</span>
            @endforelse
        </div>
    </div>
    <div style="flex:1;min-width:260px;"><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">관리 메모</label>
        <input name="memo" class="input" value="{{ old('memo', $coupon->memo ?? '') }}" maxlength="255" placeholder="내부 관리용(회원에게 안 보임)"></div>
</div>
