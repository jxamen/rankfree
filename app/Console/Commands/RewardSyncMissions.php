<?php

namespace App\Console\Commands;

use App\Domain\Reward\MissionSync;
use Illuminate\Console\Command;

/**
 * 세부주문서 → 리워드 미션 동기화 (C12 — 역할 분담):
 * - 5분: --incremental (한도 변경 반영이 목적)
 * - 매일 08:00: 전량 대조 + 신규 draft 생성 + 종료/취소 정리
 * - 매일 00:05: --counters-only (당일+익일 카운터 행 선생성)
 * 어드민 저장 훅(MarketingOrderController::updateItems)이 즉시 반영을 담당한다.
 */
class RewardSyncMissions extends Command
{
    protected $signature = 'reward:sync-missions {--incremental : 최근 변경분만 upsert} {--order= : 특정 주문만 즉시 동기화} {--counters-only : 일 카운터 선생성만 수행}';

    protected $description = '세부주문서를 리워드 미션으로 동기화하고 일 카운터를 선생성한다';

    public function handle(MissionSync $sync): int
    {
        if ($this->option('counters-only')) {
            $n = $sync->ensureDailyCounters();
            $this->info("카운터 선생성: {$n}행");

            return self::SUCCESS;
        }

        $result = $sync->sync(
            incremental: (bool) $this->option('incremental'),
            orderId: $this->option('order') ? (int) $this->option('order') : null,
        );

        if (isset($result['error'])) {
            $this->error('동기화 중단: '.$result['error']);

            return self::FAILURE;
        }

        $this->info(sprintf(
            '동기화 %d건 (신규 %d · draft %d · 종료 %d · 취소 %d)',
            $result['synced'], $result['created'], $result['drafted'], $result['ended'], $result['canceled'],
        ));

        return self::SUCCESS;
    }
}
