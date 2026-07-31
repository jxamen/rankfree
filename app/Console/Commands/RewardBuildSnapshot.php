<?php

namespace App\Console\Commands;

use App\Domain\Reward\MissionSnapshot;
use Illuminate\Console\Command;

/** 노출 스냅샷 굽기(매분) — 전 서버가 같은 목록을 보는 DB 원장(design-01 §2-5). */
class RewardBuildSnapshot extends Command
{
    protected $signature = 'reward:build-snapshot';

    protected $description = '노출 후보 미션을 reward_mission_snapshots 에 JSON 으로 굽는다';

    public function handle(MissionSnapshot $snapshot): int
    {
        $n = count($snapshot->build());
        $this->info("스냅샷 갱신: {$n}건");

        return self::SUCCESS;
    }
}
