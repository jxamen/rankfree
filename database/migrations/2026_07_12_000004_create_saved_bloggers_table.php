<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 저장 블로거 — 키워드 분석 결과에서 사용자가 찜한 블로거(키워드 × blog_id 조합, 저장 시점 스냅샷 보관). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_bloggers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('keyword', 120);            // 수집 당시 검색 키워드
            $table->string('blog_id', 40);             // 네이버 블로그 ID
            $table->string('blog_name', 150)->nullable();
            $table->decimal('score', 5, 1)->nullable();
            $table->string('grade', 2)->nullable();
            $table->longText('data')->nullable();      // 저장 시점 블로거 행 스냅샷 JSON
            $table->timestamps();
            $table->unique(['user_id', 'keyword', 'blog_id']);
            $table->index(['user_id', 'keyword']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_bloggers');
    }
};
