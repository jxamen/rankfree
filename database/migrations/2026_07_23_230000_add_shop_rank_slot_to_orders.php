<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 주문 ↔ 쇼핑 순위추적 연결(2026-07-23) — 진행중 전환 시 자동 등록, 광고주가 주문 내역에서 순위 확인. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_orders', function (Blueprint $t) {
            $t->foreignId('shop_rank_slot_id')->nullable()->after('user_coupon_id')
                ->constrained('shop_rank_slots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('marketing_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('shop_rank_slot_id');
        });
    }
};
