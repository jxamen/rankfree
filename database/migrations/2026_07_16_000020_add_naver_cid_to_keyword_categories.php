<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 키워드 허브 — 데이터랩 쇼핑인사이트 카테고리(cid) 매핑(재수집·동기화용). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_categories', function (Blueprint $table) {
            $table->unsignedBigInteger('naver_cid')->nullable()->after('slug')->index();
        });
    }

    public function down(): void
    {
        Schema::table('keyword_categories', function (Blueprint $table) {
            $table->dropColumn('naver_cid');
        });
    }
};
