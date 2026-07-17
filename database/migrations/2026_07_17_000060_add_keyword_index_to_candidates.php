<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 키워드 탐색 목록 성능 — 운영 99만 행에서 groupBy(keyword) 페이징이 18.5초(풀스캔+filesort, 실측).
 * 키워드 단위로 합쳐 보려면 (keyword, monthly_total) 정렬을 인덱스로 처리해야 한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_candidates', function (Blueprint $t) {
            $t->index(['keyword', 'monthly_total'], 'kc_keyword_vol');
            $t->index(['category_id', 'monthly_total'], 'kc_cat_vol');
        });
    }

    public function down(): void
    {
        Schema::table('keyword_candidates', function (Blueprint $t) {
            $t->dropIndex('kc_keyword_vol');
            $t->dropIndex('kc_cat_vol');
        });
    }
};
