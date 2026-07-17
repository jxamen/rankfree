<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 플레이스 업체 마스터 + 키워드×업체 순위 매핑(월별 파티션).
 *
 * 배경: 키워드 67만 × 업체 300개를 키워드마다 통째로 저장하면 52.8GB(실측 82.3KB/건)에 2억 행이 된다.
 * 같은 업체가 여러 키워드에 반복 노출되므로(실측: 같은 지역 5개 키워드에서 13.6% 중복, 전국 기준 훨씬 큼)
 *   ① 업체 정보는 place_id 기준으로 '한 번만' 저장하고(place_businesses)
 *   ② 키워드↔업체는 순위만 가진 경량 행으로 잇는다(keyword_place_ranks).
 * 순위는 매일 바뀌므로 매핑은 collected_month 로 월별 RANGE 파티션 — 특정 월만 조회/삭제(DROP PARTITION)할 수 있다.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 업체 마스터 — 여러 키워드에 같은 업체가 나와도 1행 ──
        Schema::create('place_businesses', function (Blueprint $t) {
            $t->string('place_id', 32)->primary();      // 네이버 place id
            $t->string('name', 191)->index();
            $t->string('category', 60)->nullable();
            $t->string('address', 191)->nullable();
            $t->unsignedInteger('visitor_cnt')->nullable();
            $t->unsignedInteger('blog_cnt')->nullable();
            $t->unsignedInteger('booking_cnt')->nullable();
            $t->unsignedInteger('save_cnt')->nullable();
            $t->unsignedInteger('img_cnt')->nullable();
            $t->decimal('review_score', 3, 2)->nullable();
            $t->boolean('place_plus')->default(false)->index();
            $t->boolean('new_opening')->default(false)->index();
            $t->string('talktalk_id', 60)->nullable()->index();
            $t->string('talktalk_url', 255)->nullable();
            $t->timestamp('seen_at')->nullable();       // 마지막으로 SERP 에서 본 시각
            $t->timestamps();
        });

        // ── 키워드 × 업체 순위 매핑 — 행당 수십 바이트 ──
        // 파티션 키를 PK 에 포함해야 하므로(MySQL/MariaDB 제약) 복합 PK 로 만든다.
        Schema::create('keyword_place_ranks', function (Blueprint $t) {
            $t->unsignedBigInteger('id', false)->autoIncrement()->startingValue(1);
            $t->string('keyword', 120);
            $t->string('cat', 20)->default('place');
            $t->string('place_id', 32);
            $t->unsignedSmallInteger('rnk');
            $t->unsignedInteger('collected_month');     // YYYYMM — 파티션 키
            $t->timestamp('collected_at')->nullable();
        });

        // 파티션·복합 PK 는 MariaDB/MySQL 전용 — 로컬 sqlite 는 인덱스만(테스트는 동작하되 파티션 없음)
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE keyword_place_ranks DROP PRIMARY KEY, ADD PRIMARY KEY (id, collected_month)');
            DB::statement('ALTER TABLE keyword_place_ranks ADD INDEX kpr_kw (keyword, cat, collected_month)');
            DB::statement('ALTER TABLE keyword_place_ranks ADD INDEX kpr_place (place_id, collected_month)');
            $this->partitionByMonth('keyword_place_ranks');
        } else {
            Schema::table('keyword_place_ranks', function (Blueprint $t) {
                $t->index(['keyword', 'cat', 'collected_month'], 'kpr_kw');
                $t->index(['place_id', 'collected_month'], 'kpr_place');
            });
        }
    }

    /** collected_month 기준 월별 RANGE 파티션 — 이번 달부터 12개월 + 나머지(pmax). */
    private function partitionByMonth(string $table): void
    {
        $parts = [];
        $c = now()->startOfMonth();
        for ($i = 0; $i < 13; $i++) {
            $c2 = $c->copy()->addMonth();
            $parts[] = sprintf("PARTITION p%s VALUES LESS THAN (%s)", $c->format('Ym'), $c2->format('Ym'));
            $c = $c2;
        }
        $parts[] = 'PARTITION pmax VALUES LESS THAN MAXVALUE';

        DB::statement("ALTER TABLE {$table} PARTITION BY RANGE (collected_month) (".implode(', ', $parts).')');
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_place_ranks');
        Schema::dropIfExists('place_businesses');
    }
};
