<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 주문 ↔ 플레이스 순위추적 연결(2026-07-24) — 쇼핑(shop_rank_slot_id)과 동일 패턴. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_orders', function (Blueprint $t) {
            $t->foreignId('place_rank_slot_id')->nullable()->after('shop_rank_slot_id')
                ->constrained('place_rank_slots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('marketing_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('place_rank_slot_id');
        });
    }
};
