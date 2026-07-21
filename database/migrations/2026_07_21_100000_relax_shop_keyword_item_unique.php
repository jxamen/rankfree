<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 쇼핑 노출 키워드(25) — 토큰 unique 를 소스 단위로 완화.
 * "함께 많이 찾는" 키워드가 검색광고 추천에도 있으면 그 섹션에서 빠져 실제 검색 화면과
 * 개수가 어긋났다(전역 unique). 소스별로는 여전히 중복 금지. combo 는 source 가 항상
 * 'combo' 라 종전과 동일하게 조합 중복이 막힌다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_keyword_analysis_items', function (Blueprint $t) {
            $t->dropUnique('ska_uni');
        });
        Schema::table('shop_keyword_analysis_items', function (Blueprint $t) {
            $t->unique(['analysis_id', 'kind', 'source', 'keyword'], 'ska_uni_src');
        });
    }

    public function down(): void
    {
        Schema::table('shop_keyword_analysis_items', function (Blueprint $t) {
            $t->dropUnique('ska_uni_src');
        });
        Schema::table('shop_keyword_analysis_items', function (Blueprint $t) {
            $t->unique(['analysis_id', 'kind', 'keyword'], 'ska_uni');
        });
    }
};
