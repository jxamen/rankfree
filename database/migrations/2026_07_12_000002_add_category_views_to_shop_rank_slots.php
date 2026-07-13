<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 쇼핑 순위추적 슬롯에 최종 카테고리명·월간 조회수 표시용 컬럼 추가. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_rank_slots', function (Blueprint $table) {
            $table->string('category', 150)->nullable()->after('keyword');       // 최종 카테고리명
            $table->unsignedInteger('monthly_views')->nullable()->after('category'); // 월간 조회수
        });
    }

    public function down(): void
    {
        Schema::table('shop_rank_slots', function (Blueprint $table) {
            $table->dropColumn(['category', 'monthly_views']);
        });
    }
};
