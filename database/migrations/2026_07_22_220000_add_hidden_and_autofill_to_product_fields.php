<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 내부(숨김) 필드 + 자동 채움(2026-07-22) — 고객 주문 폼엔 숨기고, 외부 발주 전달에 필요한 값은
 * 연결된 쇼핑 유입키워드 분석의 확장 수집 정보(상점명·태그·썸네일·가격·상품ID…)로 자동 채운다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_fields', function (Blueprint $table) {
            $table->boolean('is_hidden')->default(false)->after('is_required');       // 고객 주문 폼 미노출(내부용)
            $table->string('autofill_source', 40)->nullable()->after('is_hidden');    // 유입키워드 수집값 매핑(널=수동)
        });
    }

    public function down(): void
    {
        Schema::table('product_fields', function (Blueprint $table) {
            $table->dropColumn(['is_hidden', 'autofill_source']);
        });
    }
};
