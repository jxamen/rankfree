<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 상품 대표이미지(썸네일) URL — 쇼핑 유입 발주에 필요(2026-07-22). 확장 상품페이지 수집으로 채운다. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_product_infos', function (Blueprint $table) {
            $table->string('thumbnail_url', 500)->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('shop_product_infos', function (Blueprint $table) {
            $table->dropColumn('thumbnail_url');
        });
    }
};
