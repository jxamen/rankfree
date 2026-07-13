<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 순위 기록에 블로그 리뷰 수 추가 — 날짜 카드(영/블/저장) 표기용. checker 는 이미 반환 중. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('place_rank_records', function (Blueprint $table) {
            $table->unsignedInteger('blog_review_count')->nullable()->after('review_count');
        });
    }

    public function down(): void
    {
        Schema::table('place_rank_records', function (Blueprint $table) {
            $table->dropColumn('blog_review_count');
        });
    }
};
