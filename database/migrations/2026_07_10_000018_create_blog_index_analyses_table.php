<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 블로그 지수 분석 이력 — 사용자별 스냅샷(키워드→블로거 목록 / 블로그ID→단건). 과거 분석 확인용. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_index_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12);              // 'keyword' | 'blog'
            $table->string('query', 120);            // 키워드 또는 blogId
            $table->string('title', 150)->nullable(); // 표시명(블로그명 / 키워드)
            $table->decimal('score', 5, 1)->nullable();
            $table->string('grade', 2)->nullable();
            $table->unsignedSmallInteger('blogger_count')->default(0); // 키워드 분석 시 블로거 수
            $table->longText('snapshot')->nullable(); // 분석 결과 JSON (encrypted array)
            $table->timestamps();
            $table->index(['user_id', 'type', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_index_analyses');
    }
};
