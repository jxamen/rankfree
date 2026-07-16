<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 구글 서치 콘솔 검색 성과 적재 — 일별 × 차원(date 합계 · query · page · device). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsc_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('dimension', 10);           // date(일 합계) | query | page | device
            $table->string('value', 500)->default(''); // 검색어 · 페이지 URL · 기기명 (date 는 빈 값)
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 6, 4)->default(0);
            $table->decimal('position', 7, 2)->default(0);
            $table->timestamps();
            $table->unique(['date', 'dimension', 'value']);
            $table->index(['dimension', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_stats');
    }
};
