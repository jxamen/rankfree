<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 키워드 허브 — 순위 매핑(keyword_place_ranks·keyword_shop_ranks) 월 파티션 로테이션.
 *
 * 테이블 생성 시점에 13개월치 파티션만 고정 생성되므로, 그 이후의 신규 월은 전부 pmax 한
 * 파티션으로 몰려 파티션 프루닝 효과가 사라진다. 이 커맨드가 ① 이번 달~2개월 뒤 파티션을
 * 미리 만들고(pmax REORGANIZE — 선생성이라 pmax 가 비어 있어 즉시 완료) ② 보존 개월 수를
 * 지난 월 파티션을 DROP(즉시)한다. sqlite(로컬/테스트)는 파티션이 없으므로 보존기간 지난
 * 행 DELETE 만 수행한다.
 */
class HubPartitionRotate extends Command
{
    protected $signature = 'hub:partition-rotate
        {--retention= : 보존 개월 수(현재 월 포함, 기본 config rankfree.hub.rank_retention_months, 0 이하=파기 안 함)}';

    protected $description = '키워드 허브 — 순위 매핑 월 파티션 선생성 + 보존기간 지난 월 파기(22)';

    private const TABLES = ['keyword_place_ranks', 'keyword_shop_ranks'];

    /** 이번 달 포함 몇 개월 뒤 파티션까지 미리 만들어 둘지 */
    private const MONTHS_AHEAD = 2;

    public function handle(): int
    {
        $opt = $this->option('retention');
        $retention = (int) ($opt !== null ? $opt : config('rankfree.hub.rank_retention_months', 13));
        $cutoff = $retention > 0 ? (int) now()->startOfMonth()->subMonths($retention - 1)->format('Ym') : null;

        foreach (self::TABLES as $table) {
            $this->rotate($table, $cutoff);
        }

        return self::SUCCESS;
    }

    private function rotate(string $table, ?int $cutoff): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            if ($cutoff) {
                $n = DB::table($table)->where('collected_month', '<', $cutoff)->delete();
                $this->info("{$table}: 보존기간 지난 {$n}행 삭제(< {$cutoff})");
            }

            return;
        }

        $rows = DB::select(
            'SELECT PARTITION_NAME name, PARTITION_DESCRIPTION bound FROM information_schema.PARTITIONS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL',
            [$table]
        );
        if (! $rows) {
            $this->warn("{$table}: 파티션 미구성 — 건너뜀");

            return;
        }
        $months = collect($rows)->filter(fn ($r) => preg_match('/^p(\d{6})$/', $r->name))
            ->map(fn ($r) => (int) substr($r->name, 1))->sort()->values();
        $hasMax = collect($rows)->contains(fn ($r) => $r->bound === 'MAXVALUE');

        // ── ① 이번 달~MONTHS_AHEAD 개월 뒤 파티션 선생성 ──
        for ($i = 0; $i <= self::MONTHS_AHEAD; $i++) {
            $m = now()->startOfMonth()->addMonths($i);
            $ym = (int) $m->format('Ym');
            $next = (int) $m->copy()->addMonth()->format('Ym');
            // 기존 최댓값 이하는 이미 파티션 경계에 덮여 있다(RANGE 는 연속) — 새로 못 만들고 만들 필요도 없음
            if ($months->contains($ym) || ($months->isNotEmpty() && $ym <= $months->max())) {
                continue;
            }
            if ($hasMax) {
                DB::statement("ALTER TABLE {$table} REORGANIZE PARTITION pmax INTO (
                    PARTITION p{$ym} VALUES LESS THAN ({$next}),
                    PARTITION pmax VALUES LESS THAN MAXVALUE)");
            } else {
                DB::statement("ALTER TABLE {$table} ADD PARTITION (PARTITION p{$ym} VALUES LESS THAN ({$next}))");
            }
            $months->push($ym);
            $this->info("{$table}: 파티션 p{$ym} 생성");
        }

        // ── ② 보존기간 지난 월 파기 — DROP PARTITION 은 즉시 완료 ──
        if ($cutoff) {
            foreach ($months->filter(fn ($ym) => $ym < $cutoff) as $ym) {
                DB::statement("ALTER TABLE {$table} DROP PARTITION p{$ym}");
                $this->info("{$table}: 파티션 p{$ym} 파기(보존기간 경과)");
            }
        }
    }
}
