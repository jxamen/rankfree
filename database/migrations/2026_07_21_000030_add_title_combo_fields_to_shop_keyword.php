<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 쇼핑 노출 키워드(25) — 조합 재료를 내 제품 제목 단어·업체명·가격 중심으로 바꾸고,
 * 노출 안 되는 조합을 감춰(hidden) 두고 "새로 조합"으로 계속 확장하기 위한 필드.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_keyword_analyses', function (Blueprint $t) {
            $t->unsignedInteger('product_price')->nullable()->after('product_title');
            $t->json('banned')->nullable()->after('status');   // 사용자가 삭제한 단어(재생성 제외)
        });
        Schema::table('shop_keyword_analysis_items', function (Blueprint $t) {
            $t->boolean('hidden')->default(false)->after('ad_exposed');   // 노출 실패로 감춘 조합(재시도 기록)
        });
    }

    public function down(): void
    {
        Schema::table('shop_keyword_analyses', function (Blueprint $t) {
            $t->dropColumn(['product_price', 'banned']);
        });
        Schema::table('shop_keyword_analysis_items', function (Blueprint $t) {
            $t->dropColumn('hidden');
        });
    }
};
