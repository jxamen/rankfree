<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 상품×업체(배분) 단위 구글시트 탭(2026-07-22) — 같은 업체를 쓰는 상품끼리 탭이 달라야 해서
 * 업체 공유값(vendors.gsheet_tab)을 덮어쓰던 방식을 배분 단위 오버라이드로 바꾼다(null=업체 기본 탭).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_vendors', function (Blueprint $table) {
            $table->string('sheet_tab', 120)->nullable()->after('field_map');
        });
    }

    public function down(): void
    {
        Schema::table('product_vendors', function (Blueprint $table) {
            $table->dropColumn('sheet_tab');
        });
    }
};
