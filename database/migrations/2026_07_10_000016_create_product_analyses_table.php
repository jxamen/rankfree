<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 스마트스토어 상품 분석(리뷰 분석) 저장 — 확장 프로그램 수집분. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('origin_product_no');
            $table->unsignedBigInteger('merchant_no')->nullable();
            $table->string('name', 200);
            $table->string('url', 500);
            $table->string('store', 80)->nullable();
            $table->unsignedInteger('total_reviews')->default(0);
            $table->unsignedInteger('analyzed_reviews')->default(0);
            $table->decimal('avg_score', 3, 2)->default(0);
            $table->decimal('repurchase_pct', 5, 1)->default(0);
            $table->unsignedInteger('recent_7d')->default(0);
            $table->unsignedInteger('recent_1m')->default(0);
            $table->unsignedInteger('recent_3m')->default(0);
            $table->unsignedInteger('sales_6m')->nullable(); // 6개월 판매량(추출/입력)
            $table->unsignedInteger('price')->nullable();    // 판매가
            $table->json('snapshot'); // 평점분포·옵션·약점 단어 등
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'origin_product_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_analyses');
    }
};
