<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 쇼핑 상품정보에 배송비 추가(2026-09-10) — 확장이 상품 상세에서 제목·가격과 함께 수집한다.
 *
 * NULL 은 "아직 수집 안 됨", 0 은 "무료배송"이다. 둘을 한 값으로 뭉치면
 * 이미 수집한 무료배송 상품을 매번 다시 수집하게 된다.
 * 조건부 무료(N원 이상 무료)는 1개 구매 시 실제로 부담하는 baseFee 를 담는다 — 셀러력 스코어러와 같은 해석.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_product_infos', function (Blueprint $t) {
            $t->unsignedInteger('delivery_fee')->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('shop_product_infos', function (Blueprint $t) {
            $t->dropColumn('delivery_fee');
        });
    }
};
