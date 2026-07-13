<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 키워드 분석 검색 내역 — 사용자별 최근 검색 키워드(재조회용). 같은 키워드는 갱신. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('keyword', 120);
            $table->unsignedBigInteger('monthly_total')->default(0);
            $table->unsignedBigInteger('monthly_pc')->default(0);
            $table->unsignedBigInteger('monthly_mobile')->default(0);
            $table->string('comp_idx', 20)->nullable();
            $table->string('grade', 4)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'keyword']);
            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_searches');
    }
};
