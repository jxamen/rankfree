<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 세부주문서(일할 주문, 2026-07-23) — 기간형(daily) 주문 1건을 진행일 단위로 쪼갠 관리 단위.
 * 회차별로 업체를 분산 배정하고 Short URL 을 순차 배정해, 매일 1건씩 외부 발주를 자동 전송한다.
 * 1회성(total) 상품·기존 주문은 세부주문 없이 종전 흐름 그대로 동작한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained('marketing_orders')->cascadeOnDelete();
            $t->unsignedSmallInteger('day_no');                    // 회차(1~기간일수)
            $t->date('work_date');                                 // 진행일(이 회차를 발주할 날)
            $t->unsignedInteger('quantity');                       // 그 날 수량(일수량)
            $t->string('short_url', 500)->nullable();              // 회차 배정 Short URL(스냅샷 — 수동 교체 가능)
            $t->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();   // 배정 업체(회차별 분산·수동 변경)
            $t->string('status', 20)->default('pending');          // pending/sent/failed/canceled
            $t->foreignId('dispatch_id')->nullable();              // 최근 전송 기록(order_dispatches)
            $t->timestamps();
            $t->unique(['order_id', 'day_no']);
            $t->index(['status', 'work_date']);                    // 스케줄러 due 조회용
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_order_items');
    }
};
