<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 분석 출처(2026-07-29) — 'admin'(관리자 화면·주문) | 'api'(외부 API).
 * 외부 API 로 만든 분석만 확장이 순위 확인을 이어받는다(서버 자동 체크 없음).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_keyword_analyses', function (Blueprint $table) {
            $table->string('created_via', 16)->default('admin')->after('check_method');
        });
    }

    public function down(): void
    {
        Schema::table('shop_keyword_analyses', function (Blueprint $table) {
            $table->dropColumn('created_via');
        });
    }
};
