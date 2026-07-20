<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 쇼핑 노출 키워드(25) — 순위 소스를 모바일 검색 가격비교 오가닉으로 바꾸며,
 * 동일 상품이 광고로도 노출 중인지(ad_exposed) 를 조합별로 기록.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_keyword_analysis_items', function (Blueprint $t) {
            $t->boolean('ad_exposed')->nullable()->after('rank');
        });
    }

    public function down(): void
    {
        Schema::table('shop_keyword_analysis_items', function (Blueprint $t) {
            $t->dropColumn('ad_exposed');
        });
    }
};
