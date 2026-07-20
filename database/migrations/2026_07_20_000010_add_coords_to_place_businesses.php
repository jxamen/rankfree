<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 플레이스 업체 좌표(경도 x · 위도 y) + 공통주소(시/구/동) — pcmap SERP 항목에 그대로 온다.
 * 지리 기반 "주변 추천"(거리순 다른 카테고리 업체/키워드) 내부링크에 사용.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('place_businesses', function (Blueprint $t) {
            $t->decimal('x', 10, 7)->nullable()->after('address');   // 경도(126.92xxxxx)
            $t->decimal('y', 10, 7)->nullable()->after('x');         // 위도(37.55xxxxx)
            $t->string('common_address', 120)->nullable()->after('y'); // "서울 마포구 서교동"
            $t->index(['x', 'y'], 'pb_xy');   // 바운딩박스(주변) 조회용
        });
    }

    public function down(): void
    {
        Schema::table('place_businesses', function (Blueprint $t) {
            $t->dropIndex('pb_xy');
            $t->dropColumn(['x', 'y', 'common_address']);
        });
    }
};
