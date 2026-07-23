<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 세부주문서를 일×업체 단위로 (2026-07-23 당일 개정) —
 * 하루 발주량(고객 일수량 × 기본 이행률)을 업체 배분 비율로 나눠 하루에 업체 수만큼 생성한다.
 * (order_id, day_no) 유니크를 풀고 조회 인덱스로 대체.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL 1553 방지 — order_id FK 가 유니크를 지지 인덱스로 쓰므로, 대체 인덱스를 먼저 만들고 지운다
        Schema::table('marketing_order_items', function (Blueprint $t) {
            $t->index(['order_id', 'day_no']);
        });
        Schema::table('marketing_order_items', function (Blueprint $t) {
            $t->dropUnique(['order_id', 'day_no']);
        });
    }

    public function down(): void
    {
        Schema::table('marketing_order_items', function (Blueprint $t) {
            $t->unique(['order_id', 'day_no']);
        });
        Schema::table('marketing_order_items', function (Blueprint $t) {
            $t->dropIndex(['order_id', 'day_no']);
        });
    }
};
