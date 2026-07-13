<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D6 사진 충실도(imageCount) 신설.
 *  - place_seo_scores.d6  : N2 세부지표 D6 점수(0~100, 전 업종 list 가용)
 *  - place_seo_serp.image_cnt : 원시 등록 사진 수(pcmap 리스트 imageCount). velocity·진단용.
 * imageCount 는 saveCount(restaurant 전용)와 달리 전 업종 pcmap 리스트에 실값 제공 — 실측 확인.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('place_seo_scores', function (Blueprint $table) {
            $table->decimal('d6', 6, 3)->nullable()->after('d5');
        });
        Schema::table('place_seo_serp', function (Blueprint $table) {
            $table->integer('image_cnt')->nullable()->after('save_cnt');
        });
    }

    public function down(): void
    {
        Schema::table('place_seo_scores', function (Blueprint $table) {
            $table->dropColumn('d6');
        });
        Schema::table('place_seo_serp', function (Blueprint $table) {
            $table->dropColumn('image_cnt');
        });
    }
};
