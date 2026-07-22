<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 마케팅 상품 노출 순서 — 관리자 드래그 정렬용. 기존 카탈로그 순서(유형→id)를 초기값으로 백필. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_products', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('is_active');
        });

        foreach (DB::table('marketing_products')->orderBy('product_type')->orderBy('id')->pluck('id') as $i => $id) {
            DB::table('marketing_products')->where('id', $id)->update(['sort_order' => $i]);
        }
    }

    public function down(): void
    {
        Schema::table('marketing_products', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
