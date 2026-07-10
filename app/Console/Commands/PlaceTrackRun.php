<?php

namespace App\Console\Commands;

use App\Domain\Place\RankSlotService;
use App\Models\PlaceRankSlot;
use Illuminate\Console\Command;

/** 활성 순위 추적 슬롯의 오늘 순위를 조회·기록 (일일 배치 / 스케줄러). */
class PlaceTrackRun extends Command
{
    protected $signature = 'place:track-run {--slot= : 특정 슬롯만} {--user= : 특정 유저만}';

    protected $description = '활성 순위 추적 슬롯의 오늘 순위 조회·기록';

    public function handle(RankSlotService $service): int
    {
        $q = PlaceRankSlot::where('is_active', true);
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
            if ($r['blocked']) {
                $this->warn('차단 감지 → 중단 (nCaptcha 토큰 재발급 필요)');
                break;
            }
            $this->line($slot->keyword.' → '.($r['found'] ? $r['rank'].'위' : '300+'));
            $done++;
            sleep(2);
        }

        $this->info("완료: {$done}건 기록");

        return self::SUCCESS;
    }
}
