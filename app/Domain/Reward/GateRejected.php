<?php

namespace App\Domain\Reward;

use RuntimeException;

/** 확정 트랜잭션 내부 게이트 거절 — 트랜잭션을 롤백시키고 사유를 밖으로 전달한다(거절 로그는 롤백 밖에서). */
class GateRejected extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $userMessage)
    {
        parent::__construct($reason);
    }
}
