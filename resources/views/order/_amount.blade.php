{{-- 예상 결제 금액 — 상품 단가는 부가세 별도. 공급가액·부가세를 나눠 보여주고 결제 금액(부가세 포함)을 강조한다.
     값은 order/show 의 calc() 가 실시간으로 채운다(최종 금액은 서버가 재계산). --}}
<div>
    <span class="text-muted" style="font-size:var(--fs-xs);">예상 결제 금액</span>
    <div id="o-discount-row" style="font-size:var(--fs-xs);color:var(--color-success);display:none;">쿠폰 할인 -<span id="o-discount">0</span>원</div>
    <div class="text-muted-soft" style="font-size:var(--fs-xs);">공급가액 <span id="o-supply">0</span>원 + 부가세 <span id="o-vat">0</span>원</div>
    <div class="text-ink font-display" style="font-size:var(--fs-xl);"><span id="o-total">0</span>원<span class="text-muted-soft" style="font-size:var(--fs-xs);font-weight:400;margin-left:6px;">부가세 포함</span></div>
</div>
