<?php

namespace App\Support;

/**
 * 부가가치세 — 상품 단가와 주문 total_price 는 모두 공급가액(부가세 별도)이다.
 * 고객에게 안내하는 결제·입금 금액은 여기서 부가세를 더해 만든다(저장값은 건드리지 않는다).
 */
class Vat
{
    /** 부가세율. 주문 폼의 실시간 계산 JS 도 이 값을 data 속성으로 넘겨받아 쓴다. */
    public const RATE = 0.1;

    /** 공급가액에 붙는 부가세 — 원 단위 절사. */
    public static function of(float $supply): int
    {
        return (int) floor($supply * self::RATE);
    }

    /** 공급가액 + 부가세 = 실제 결제(입금) 금액. */
    public static function total(float $supply): int
    {
        return (int) floor($supply) + self::of($supply);
    }
}
