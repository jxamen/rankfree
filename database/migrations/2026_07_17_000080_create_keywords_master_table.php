<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 키워드 마스터 — 목록·정렬·페이징을 인덱스만으로 처리하기 위한 분리.
 *
 * 문제: keyword_candidates 는 '키워드 × 분류' 라 99만 행인데 고유 키워드는 26.8만이다.
 *       키워드 단위로 합쳐 정렬(monthly_total desc)하면 매번 풀스캔+filesort → 목록 18.5초(실측),
 *       중복 제거 서브쿼리로 낮춰도 3.1초. 플레이스(67만)도 10초 이상.
 * 해결: 키워드를 유니크 마스터로 분리하고 (type, monthly_total) 인덱스로 정렬 → 인덱스 스캔.
 *       분류 매핑은 keyword_candidates 에 그대로 두고 필요할 때만 조인한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keywords', function (Blueprint $t) {
            $t->id();
            $t->string('keyword', 120);
            $t->string('type', 12);                   // place | shopping
            $t->string('region', 60)->nullable();     // 플레이스 지역(대표)
            $t->string('region_type', 20)->nullable();
            $t->string('source', 20)->nullable();     // 대표 출처
            $t->unsignedBigInteger('monthly_total')->nullable();
            $t->string('comp_idx', 12)->nullable();
            $t->timestamp('volume_checked_at')->nullable();
            $t->timestamp('serp_collected_at')->nullable();   // 업체·상품 수집일(스냅샷 최신)
            $t->unsignedInteger('serp_count')->default(0);    // 수집한 업체·상품 수
            $t->unsignedSmallInteger('cat_cnt')->default(1);  // 이 키워드가 속한 분류 수
            $t->unsignedBigInteger('category_id')->nullable(); // 대표 분류(표시용)
            $t->string('status', 12)->default('pending');     // 대표 상태(가장 진행된 것) — 허브 파이프라인은 candidates 가 기준
            $t->timestamps();

            $t->unique(['keyword', 'type']);
            $t->index(['type', 'monthly_total'], 'kw_type_vol');            // 검색량순 목록
            $t->index(['type', 'serp_collected_at'], 'kw_type_serp');       // 수집일순 목록
            $t->index(['type', 'region'], 'kw_type_region');                // 플레이스 지역 필터
            $t->index(['type', 'status'], 'kw_type_status');                // 상태 집계
        });

        // 기존 후보에서 키워드 마스터 채우기(있는 데이터 그대로 이관)
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("
                INSERT INTO keywords (keyword, type, region, region_type, source, monthly_total, comp_idx, volume_checked_at, cat_cnt, category_id, status, created_at, updated_at)
                SELECT c.keyword, cat.type,
                       MIN(c.region), MIN(c.region_type), MIN(c.source),
                       MAX(c.monthly_total), MIN(c.comp_idx), MAX(c.volume_checked_at),
                       COUNT(*), MIN(c.category_id),
                       -- 대표 상태 = 가장 진행된 것(발행 > 승인 > 대기 > 보류)
                       ELT(MAX(FIELD(c.status, 'rejected', 'pending', 'approved', 'published')),
                           'rejected', 'pending', 'approved', 'published'),
                       NOW(), NOW()
                FROM keyword_candidates c
                JOIN keyword_categories cat ON cat.id = c.category_id
                GROUP BY c.keyword, cat.type
            ");

            // 이미 수집된 스냅샷의 수집일·건수 반영
            foreach (['place' => 'keyword_place_ranks', 'shopping' => 'keyword_shop_ranks'] as $type => $tbl) {
                if (! Schema::hasTable($tbl)) {
                    continue;
                }
                DB::statement("
                    UPDATE keywords k
                    JOIN (SELECT keyword, MAX(collected_at) at, COUNT(*) c FROM {$tbl} GROUP BY keyword) s
                      ON s.keyword = k.keyword
                    SET k.serp_collected_at = s.at, k.serp_count = s.c
                    WHERE k.type = '{$type}'
                ");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('keywords');
    }
};
