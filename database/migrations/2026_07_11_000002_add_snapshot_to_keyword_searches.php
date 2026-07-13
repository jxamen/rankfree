<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 키워드 검색 내역에 분석 결과 스냅샷 저장 — 최근 검색 클릭 시 재수집·과금 없이 즉시 열람.
 * 재분석(재검색)만 새로 수집하고 스냅샷을 갱신한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_searches', function (Blueprint $table) {
            $table->json('snapshot')->nullable()->after('grade');
        });
    }

    public function down(): void
    {
        Schema::table('keyword_searches', function (Blueprint $table) {
            $table->dropColumn('snapshot');
        });
    }
};
