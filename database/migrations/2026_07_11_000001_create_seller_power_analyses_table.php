<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 셀러력 — 쇼핑 상품 SEO·지수 경쟁 비교 결과 스냅샷(사용자별). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_power_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('keyword', 120);            // 경쟁 비교 기준 검색어
            $table->string('product_url', 500);        // 내 상품 URL
            $table->string('product_name', 300)->nullable();
            $table->string('store_id', 100)->nullable();
            $table->float('score')->default(0);        // 셀러력 총점(0~100)
            $table->string('grade', 2)->nullable();    // S/A/B/C/D
            $table->unsignedTinyInteger('market_percentile')->default(0); // 시장 상위 %
            $table->unsignedSmallInteger('rank_in_top')->default(0);      // 상위 N개 중 순위
            $table->unsignedSmallInteger('competitor_count')->default(0);
            $table->longText('snapshot');              // 결과 JSON(axes·losses·rx·positions 등)
            $table->timestamps();

            $table->unique(['user_id', 'product_url', 'keyword'], 'sp_user_product_keyword');
            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_power_analyses');
    }
};
