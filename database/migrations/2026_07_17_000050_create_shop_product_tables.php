<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 쇼핑 상품 마스터 + 키워드×상품 순위 매핑(월별 파티션) — 플레이스와 같은 구조.
 *
 * 여러 키워드에 같은 상품이 반복 노출되므로 상품을 1회만 저장하고 순위만 매핑으로 잇는다.
 * 저장 범위는 검색 결과에 공개 노출되는 것만 — 상품명·가격·몰 이름·광고 여부·순위.
 * (판매자 개인정보(대표자·연락처·사업자번호)는 네이버가 캡차로 보호하는 영역이라 수집하지 않는다.)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 상품 마스터 ──
        Schema::create('shop_products', function (Blueprint $t) {
            $t->string('product_key', 64)->primary();   // nvMid 등 상품 식별자(없으면 링크 해시)
            $t->string('title', 300);
            $t->unsignedInteger('price')->nullable();
            $t->string('mall_name', 120)->nullable()->index();
            $t->text('link')->nullable();               // 광고 링크는 2,000자를 넘어 text
            $t->boolean('is_ad')->default(false)->index();
            $t->timestamp('seen_at')->nullable();
            $t->timestamps();
        });

        // ── 몰(판매처) 마스터 — 공개된 몰 이름 단위 집계용 ──
        Schema::create('shop_malls', function (Blueprint $t) {
            $t->id();
            $t->string('mall_name', 120)->unique();
            $t->unsignedInteger('product_cnt')->default(0);
            $t->timestamp('seen_at')->nullable();
            $t->timestamps();
        });

        // ── 키워드 × 상품 순위 매핑(월별 파티션) ──
        Schema::create('keyword_shop_ranks', function (Blueprint $t) {
            $t->unsignedBigInteger('id', false)->autoIncrement()->startingValue(1);
            $t->string('keyword', 120);
            $t->string('product_key', 64);
            $t->unsignedSmallInteger('rnk');
            $t->boolean('is_ad')->default(false);
            $t->unsignedInteger('collected_month');     // YYYYMM — 파티션 키
            $t->timestamp('collected_at')->nullable();
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE keyword_shop_ranks DROP PRIMARY KEY, ADD PRIMARY KEY (id, collected_month)');
            DB::statement('ALTER TABLE keyword_shop_ranks ADD INDEX ksr_kw (keyword, collected_month)');
            DB::statement('ALTER TABLE keyword_shop_ranks ADD INDEX ksr_prod (product_key, collected_month)');
            $this->partitionByMonth('keyword_shop_ranks');
        } else {
            Schema::table('keyword_shop_ranks', function (Blueprint $t) {
                $t->index(['keyword', 'collected_month'], 'ksr_kw');
                $t->index(['product_key', 'collected_month'], 'ksr_prod');
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
            $parts[] = sprintf('PARTITION p%s VALUES LESS THAN (%s)', $c->format('Ym'), $c2->format('Ym'));
            $c = $c2;
        }
        $parts[] = 'PARTITION pmax VALUES LESS THAN MAXVALUE';

        DB::statement("ALTER TABLE {$table} PARTITION BY RANGE (collected_month) (".implode(', ', $parts).')');
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_shop_ranks');
        Schema::dropIfExists('shop_malls');
        Schema::dropIfExists('shop_products');
    }
};
