<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 쇼핑 유입 주문 ↔ 노출 키워드 분석 연결(2026-07-22) — 발주 시 분석의 Short URL 을 쓰기 위한 상호 참조. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_keyword_analyses', function (Blueprint $table) {
            $table->foreignId('marketing_order_id')->nullable()->after('user_id')
                ->constrained('marketing_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shop_keyword_analyses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('marketing_order_id');
        });
    }
};
