<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 주말 몰아 발주(2026-07-24) — 켜면(true) 이 업체는 주말에 주문을 받지 않는 것으로 보고,
        // 세부주문의 토·일·월 회차를 직전 금요일에 한꺼번에 자동 발주한다. 기본은 꺼짐(정상 당일 발주).
        Schema::table('vendors', function (Blueprint $table) {
            $table->boolean('weekend_batch_dispatch')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('weekend_batch_dispatch');
        });
    }
};
