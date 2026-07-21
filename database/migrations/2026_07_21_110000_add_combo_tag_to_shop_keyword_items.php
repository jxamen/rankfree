<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 쇼핑 노출 키워드(25) — 조합 생성 유형 태그.
 * 어떤 유형(제목 단어/제목 구절/SEO태그/브랜드/속성/어미)의 조합이 노출되는지
 * 패턴을 집계하기 위해 조합 생성 규칙을 함께 보관한다. 확인된 조합은 재생성 때도
 * 삭제하지 않으므로(미확인만 정리) 결과가 패턴 데이터로 누적된다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_keyword_analysis_items', function (Blueprint $t) {
            $t->string('combo_tag', 16)->nullable()->after('keyword');   // title|phrase|tag|brand|brand_price|attr|suffix
        });
    }

    public function down(): void
    {
        Schema::table('shop_keyword_analysis_items', function (Blueprint $t) {
            $t->dropColumn('combo_tag');
        });
    }
};
