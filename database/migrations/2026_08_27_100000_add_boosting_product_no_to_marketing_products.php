<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 부스팅샵 플레이스 주문(2026-08-27) — 상품마다 부스팅샵 상품번호(47~50 유입 · 52~56 저장)가 달라
 * 전송할 때마다 운영자가 입력해야 한다. 한 번 보낸 값을 상품에 기억해 다음 주문부터 자동으로 채운다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_products', function (Blueprint $table) {
            $table->unsignedInteger('boosting_product_no')->nullable()->after('order_token');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_products', function (Blueprint $table) {
            $table->dropColumn('boosting_product_no');
        });
    }
};
