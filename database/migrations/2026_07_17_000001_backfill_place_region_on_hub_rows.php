<?php

use App\Domain\Keyword\PlaceRegionBackfiller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * 데이터 마이그레이션 — region 컬럼(2026_07_16_000030) 도입 전에 만들어진 플레이스 후보·발행 문서의
 * 빈 region 을 키워드에서 되짚어 채운다. (증상: 카테고리 허브 지역 배지가 '강남역 4' 처럼 실제보다 적게 표기)
 * 배포 시 자동 치유. 이후 지역 매트릭스가 늘면 `php artisan hub:backfill-region` 재실행.
 * 데이터 보정일 뿐이라 실패해도 배포를 막지 않는다(로그만).
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            $s = app(PlaceRegionBackfiller::class)->run();
            Log::info('hub: place region backfilled', $s);
        } catch (\Throwable $e) {
            Log::warning('hub: place region backfill skipped', ['msg' => $e->getMessage()]);
        }
    }

    public function down(): void
    {
        // 데이터 보정 — 되돌리지 않는다(원래 NULL 이 정상 상태가 아니었음).
    }
};
