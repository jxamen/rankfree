<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 순위추적 슬롯 URL 컬럼 255 → 500 확장.
 * 검증(target max:500)은 500자까지 받는데 컬럼이 255라, 검색결과에서 복사한
 * NaPm 추적 파라미터 붙은 긴 상품 URL 등록 시 22001(Data too long)로 500이 났다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_rank_slots', function (Blueprint $t) {
            $t->string('product_url', 500)->nullable()->change();
        });
        Schema::table('place_rank_slots', function (Blueprint $t) {
            $t->string('place_url', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('shop_rank_slots', function (Blueprint $t) {
            $t->string('product_url', 255)->nullable()->change();
        });
        Schema::table('place_rank_slots', function (Blueprint $t) {
            $t->string('place_url', 255)->nullable()->change();
        });
    }
};
