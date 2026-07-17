<?php

namespace App\Console\Commands;

use App\Domain\Keyword\PlaceRegionBackfiller;
use Illuminate\Console\Command;

/**
 * 키워드 허브 — 플레이스 region 백필(22). region 컬럼 도입 전 발행분의 지역이 비어
 * 카테고리 허브 지역 배지 수가 실제보다 적게 나오는 문제를 고친다. 재실행 안전.
 */
class HubBackfillRegion extends Command
{
    protected $signature = 'hub:backfill-region {--dry-run : 실제로 쓰지 않고 건수만 집계}';

    protected $description = '키워드 허브 — 플레이스 후보·발행 문서의 빈 region 을 키워드에서 되짚어 채운다(22)';

    public function handle(PlaceRegionBackfiller $backfiller): int
    {
        $dry = (bool) $this->option('dry-run');
        $s = $backfiller->run($dry);

        $this->info(sprintf(
            '%s후보 %s · 문서 %s 채움 · 미매칭 %s (지역/업종 패턴이 매트릭스에 없는 키워드)',
            $dry ? '[dry-run] ' : '',
            number_format($s['candidates']),
            number_format($s['docs']),
            number_format($s['unmatched']),
        ));

        return self::SUCCESS;
    }
}
