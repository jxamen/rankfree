<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 쇼핑 노출 키워드(25) — 순위 확인 방식 선택(2026-07-22 요청).
 * api(기본): openapi shop.json — 빠르고 차단 없음(쇼핑 순위추적과 동일 기준, 광고 판별 없음)
 * search: 통합검색(m.search) 크롤링 — 실제 모바일 화면 오가닉 순위·광고 판별, 느리고 차단 가능
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_keyword_analyses', function (Blueprint $table) {
            $table->string('check_method', 10)->default('api');
        });
    }

    public function down(): void
    {
        Schema::table('shop_keyword_analyses', function (Blueprint $table) {
            $table->dropColumn('check_method');
        });
    }
};
