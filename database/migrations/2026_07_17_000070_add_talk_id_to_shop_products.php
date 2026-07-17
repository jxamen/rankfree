<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 쇼핑 상품에 톡톡 코드 — 확장이 mallInfoCache.talkAccountId 로 이미 수집한다.
 * 관리자 상세에서 판매처 톡톡을 바로 열 수 있게 저장한다(공개 노출 정보).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_products', function (Blueprint $t) {
            $t->string('talk_id', 60)->nullable()->index()->after('mall_name');
        });
    }

    public function down(): void
    {
        Schema::table('shop_products', function (Blueprint $t) {
            $t->dropIndex(['talk_id']);
            $t->dropColumn('talk_id');
        });
    }
};
