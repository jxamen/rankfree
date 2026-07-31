<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \App\Domain\Reward\RewardCache::flushLocal();   // 프로세스 L1 이 테스트 간 새지 않게
    }
}
