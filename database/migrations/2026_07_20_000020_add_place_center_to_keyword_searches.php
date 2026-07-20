<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 허브 키워드 문서의 대표 좌표(그 키워드 상위 업체들의 중앙값) — 지리 "주변 추천" 내부링크에 사용.
 * 플레이스 SERP 수집 시 PlaceSerpStore.save 가 채운다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_searches', function (Blueprint $t) {
            $t->decimal('place_x', 10, 7)->nullable()->after('region_type'); // 대표 경도
            $t->decimal('place_y', 10, 7)->nullable()->after('place_x');     // 대표 위도
            $t->index(['place_x', 'place_y'], 'ks_place_xy');
        });
    }

    public function down(): void
    {
        Schema::table('keyword_searches', function (Blueprint $t) {
            $t->dropIndex('ks_place_xy');
            $t->dropColumn(['place_x', 'place_y']);
        });
    }
};
