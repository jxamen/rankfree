<?php

namespace App\Domain\Order;

/**
 * 주문 입력 오류 — 어떤 입력(field)이 왜 거부됐는지 담는다.
 * field 규약: 동적 필드는 'f_{field_key}', 그 외 'quantity' | 'days' | 'user_coupon_id'.
 * 웹은 폼 에러 키로, API 는 응답의 field 로 그대로 노출한다.
 */
class OrderInputException extends \DomainException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
