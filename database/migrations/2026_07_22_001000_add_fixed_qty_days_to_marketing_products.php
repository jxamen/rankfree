<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 상품 수량·기간 고정(2026-07-22 운영 요청) — 값을 넣으면 고객이 묻지도 고르지도 않고 그대로 주문한다
 * ("이 키워드는 이 상품 사세요"식 패키지 판매). 비우면 기존처럼 직접 입력.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_products', function (Blueprint $table) {
            $table->unsignedInteger('fixed_quantity')->nullable();   // 고정 수량(일수량/전체수량)
            $table->unsignedInteger('fixed_days')->nullable();       // 고정 기간(일, daily 과금만 의미)
        });
    }

    public function down(): void
    {
        Schema::table('marketing_products', function (Blueprint $table) {
            $table->dropColumn(['fixed_quantity', 'fixed_days']);
        });
    }
};
