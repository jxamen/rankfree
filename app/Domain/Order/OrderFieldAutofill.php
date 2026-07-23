<?php

namespace App\Domain\Order;

use App\Models\ShopKeywordAnalysis;
use App\Models\ShopProductInfo;

/**
 * 내부(숨김) 필드 자동 채움(2026-07-22) — 주문에 연결된 쇼핑 유입키워드 분석에서
 * 확장이 수집한 값(상점명·태그·썸네일·가격·상품ID…)을 상품 필드의 autofill_source 매핑대로
 * 주문 field_values 에 저장한다. 외부 발주(field_map `field:<key>`)가 이 값을 전달한다.
 *
 * 호출 시점: 수집요청으로 분석 생성 직후 · 확장 상품정보 수집(refreshProductInfo) 반영 후 · 관리자 수동(다시 채우기).
 */
class OrderFieldAutofill
{
    /**
     * @param  bool  $force  true=기존 값도 덮어씀(다시 채우기). 기본은 빈 필드만(수동 입력 보존).
     * @return int 채운 필드 수
     */
    public function fillFromAnalysis(ShopKeywordAnalysis $analysis, bool $force = false): int
    {
        // 관계 캐시를 쓰지 않고 매번 신선 조회 — 낡은 field_values 로 수동 입력을 덮거나 저장을 놓치지 않게
        $order = $analysis->order()->with('product.fields')->first();
        if (! $order || ! $order->product) {
            return 0;
        }

        $info = $analysis->product_id !== null && $analysis->product_id !== ''
            ? ShopProductInfo::where('user_id', $analysis->user_id)
                ->where('channel_product_id', $analysis->product_id)->first()
            : null;

        $values = [
            'core_keyword' => (string) $analysis->core_keyword,
            'product_url' => (string) $analysis->product_url,
            'product_id' => (string) $analysis->product_id,
            'product_title' => (string) ($analysis->product_title ?: $info?->title),
            'mall_name' => (string) ($analysis->mall_name ?: $info?->mall_name),
            'product_price' => $analysis->product_price !== null ? (string) $analysis->product_price : (string) ($info?->price ?? ''),
            'seller_tags' => implode(', ', array_filter(array_map('strval', (array) ($info?->seller_tags ?? [])))),
            'thumbnail_url' => (string) ($info?->thumbnail_url ?? ''),
            // Short URL 목록 — 생성돼 있어야 채워진다(없으면 발주 가드가 막는다). 그룹 순서대로 콤마 구분.
            'short_url' => $analysis->shortLinks()->orderBy('group_no')->get()->map(fn ($l) => $l->url())->implode(', '),
        ];

        $fv = (array) $order->field_values;
        $filled = 0;
        foreach ($order->product->fields as $field) {
            $src = (string) $field->autofill_source;
            if ($src === '' || ! array_key_exists($src, $values)) {
                continue;
            }
            $val = trim($values[$src]);
            if ($val === '') {
                continue;   // 아직 수집 안 된 값 — 다음 수집 때 채워진다
            }
            $current = $fv[$field->field_key] ?? null;
            if (! $force && is_string($current) && trim($current) !== '') {
                continue;   // 수동 입력 보존(강제 아님)
            }
            if (! $force && ! is_string($current) && ! empty($current)) {
                continue;
            }
            $fv[$field->field_key] = $val;
            $filled++;
        }

        if ($filled > 0) {
            $order->update(['field_values' => $fv]);
        }

        return $filled;
    }
}
