<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 경쟁분석 리뷰 주별/품질(D9·D10) 저장 컬럼. crm review_weekly/review_quality. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('place_seo_daily', function (Blueprint $table) {
            $table->text('review_weekly')->nullable()->after('review_kw');   // {"v":[..4],"b":[..4]}
            $table->text('review_quality')->nullable()->after('review_weekly'); // 사진비율·authority·ctx·bloggers
        });
    }

    public function down(): void
    {
        Schema::table('place_seo_daily', function (Blueprint $table) {
            $table->dropColumn(['review_weekly', 'review_quality']);
        });
    }
};
