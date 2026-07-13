<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 쇼핑 시장 분석(C1) 저장 — 확장 프로그램 수집분. 요약 컬럼 + 재렌더링용 snapshot JSON. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('keyword', 120);
            $table->unsignedInteger('total_count')->default(0); // 검색결과 전체 상품수
            $table->unsignedInteger('item_count')->default(0);  // 분석 대상 상품수
            $table->boolean('include_ads')->default(false);
            $table->unsignedBigInteger('sales_6m')->default(0);
            $table->unsignedBigInteger('revenue_6m')->default(0);
            $table->unsignedInteger('avg_price')->default(0);
            $table->unsignedInteger('median_price')->default(0);
            $table->decimal('top10_share', 5, 2)->default(0);
            $table->unsignedInteger('monthly_search')->nullable(); // 키워드 월간 검색량
            $table->string('comp_idx', 20)->nullable();
            $table->json('snapshot'); // related_tags, keyword_data, top_products 등
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'keyword']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_analyses');
    }
};
