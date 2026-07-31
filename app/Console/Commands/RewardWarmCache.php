<?php

namespace App\Console\Commands;

use App\Domain\Reward\MissionSnapshot;
use App\Domain\Reward\RewardCache;
use App\Domain\Reward\SlotCap;
use App\Support\RewardDay;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 워밍(매분, 서버마다) — 스냅샷을 읽어 파일 폴백(§7-6)을 굽고 C1 캐시를 데운다.
 * 🔴 withoutOverlapping() 금지 — cache_locks(공유 DB)라 다중 서버에서 1대만 돌게 된다(§7-6).
 *    대신 flock 로컬 파일 락을 커맨드 안에서 직접 건다(서버별 1개 실행 보장).
 */
class RewardWarmCache extends Command
{
    protected $signature = 'reward:warm-cache';

    protected $description = '미션 목록 파일 폴백·C1 캐시를 서버 로컬에 데운다';

    public function handle(MissionSnapshot $snapshot): int
    {
        $lock = fopen(storage_path('framework/reward-warm.lock'), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->info('이미 실행 중 — 건너뜀');

            return self::SUCCESS;
        }

        try {
            $day = RewardDay::current();
            $row = DB::table('reward_mission_snapshots')->where('slot_key', MissionSnapshot::SLOT_KEY)->first();

            // 스냅샷이 없거나 직전 농장일 기준이면 지금 굽고 그 결과를 그대로 쓴다
            // (build() 직후 readFile() 을 읽으면 아직 쓰지 않은 파일을 읽어 빈 목록을 굽게 된다)
            $rows = ($row && ($row->built_for_day === null || (string) $row->built_for_day === $day))
                ? (json_decode((string) $row->payload, true) ?: [])
                : $snapshot->build();

            $snapshot->writeFile($rows);

            // L2(공유 스토어)만 데운다 — L1 은 APCu 든 static 이든 CLI 프로세스 전용이라
            // 여기서 채워도 웹 워커에는 전달되지 않는다(워커별로 첫 요청에 자연 충전).
            if (config('reward.cache.l2_store') && ($slotNo = SlotCap::slotNo()) !== null) {
                RewardCache::forget($key = sprintf('reward:ml:%s:%d:v%d', $day, $slotNo, RewardCache::version()));
                RewardCache::remember($key,
                    (int) config('reward.cache.ttl.mission_list_l1'),
                    (int) config('reward.cache.ttl.mission_list_l2'),
                    fn () => $rows);
            }

            $this->info('워밍 완료: '.count($rows).'건');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return self::SUCCESS;
    }
}
