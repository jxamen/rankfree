<?php

namespace App\Console\Commands;

use App\Domain\Shopping\ShopRankSlotService;
use App\Models\ShopRankSlot;
use Illuminate\Console\Command;

/** 활성 쇼핑 순위 추적 슬롯의 현재 순위를 조회·기록 (스케줄러 — 매시간). */
class ShopTrackRun extends Command
{
    protected $signature = 'shop:track-run {--slot= : 특정 슬롯만} {--user= : 특정 유저만}';

    protected $description = '활성 쇼핑 순위 추적 슬롯의 순위 조회·기록';

    public function handle(ShopRankSlotService $service): int
    {
        $q = ShopRankSlot::where('is_active', true);
        if ($this->option('slot')) {
            $q->where('id', (int) $this->option('slot'));
        }
        if ($this->option('user')) {
            $q->where('user_id', (int) $this->option('user'));
        }
        $slots = $q->get();
        $this->info($slots->count().'개 슬롯 처리 시작');

        $done = 0;
        foreach ($slots as $slot) {
            $r = $service->run($slot);
            if (! empty($r['blocked']) && empty($r['found'])) {
                $this->warn('쇼핑 API 한도(429) — 중단 (다음 실행에서 재개)');
                break;
            }
            $this->line($slot->keyword.' → '.(! empty($r['found']) ? $r['rank'].'위' : '순위권 밖'));
            $done++;
            usleep(500000); // 0.5s — 슬롯 간 간격(엔진 자체 페이지 delay 존재)
        }

        $this->info("완료: {$done}건 기록");

        return self::SUCCESS;
    }
}
