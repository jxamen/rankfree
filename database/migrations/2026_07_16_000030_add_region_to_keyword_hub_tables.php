<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 키워드 허브 — 플레이스 2축 분류(업종=category_id + 지역=region).
 * 지역이 지금까지 "강남 맛집" 키워드 문자열에만 있어 지역 필터/분류가 불가능했다.
 * region(지역명: 강남·성수동…) + region_type(hotplace|district|city|dong|travel) 컬럼을 추가한다.
 * 쇼핑은 지역 개념이 없어 두 컬럼 모두 null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_candidates', function (Blueprint $t) {
            $t->string('region', 60)->nullable()->after('category_id');
            $t->string('region_type', 20)->nullable()->after('region');
            $t->index(['category_id', 'region']);
        });
        Schema::table('keyword_searches', function (Blueprint $t) {
            $t->string('region', 60)->nullable()->after('category_id');
            $t->string('region_type', 20)->nullable()->after('region');
            $t->index(['category_id', 'region']);
        });
    }

    public function down(): void
    {
        Schema::table('keyword_candidates', function (Blueprint $t) {
            $t->dropIndex(['category_id', 'region']);
            $t->dropColumn(['region', 'region_type']);
        });
        Schema::table('keyword_searches', function (Blueprint $t) {
            $t->dropIndex(['category_id', 'region']);
            $t->dropColumn(['region', 'region_type']);
        });
    }
};
