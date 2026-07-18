<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 공개 허브(/keywords) 읽기 경로 인덱스 — where origin='hub' + order by monthly_total desc.
 * 기존 (origin, refreshed_at) 만 있어 발행 문서가 늘면 인기순 정렬이 filesort 로 돌던 공백을 메운다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_searches', function (Blueprint $table) {
            $table->index(['origin', 'monthly_total'], 'ks_origin_vol');
        });
    }

    public function down(): void
    {
        Schema::table('keyword_searches', function (Blueprint $table) {
            $table->dropIndex('ks_origin_vol');
        });
    }
};
