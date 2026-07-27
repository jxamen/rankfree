<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AI 크롤러·에이전트 유입(2026-07-27) — Generative Organic 원천.
        // GPTBot·OAI-SearchBot·ChatGPT-User·PerplexityBot 등은 JS 를 실행하지 않아 GA4 에 전혀 잡히지 않는다.
        // 서버에서 직접 집계해야 "AI 가 우리 문서를 얼마나 읽어갔는지"를 볼 수 있다.
        Schema::create('ai_crawler_hits', function (Blueprint $table) {
            $table->id();
            $table->date('hit_date');
            $table->string('bot', 40);          // 정규화된 봇 이름(GPTBot·ClaudeBot …)
            $table->string('path', 255);        // 경로(쿼리 제외) — 어떤 문서가 읽혔는지
            $table->unsignedInteger('hits')->default(0);
            $table->timestamps();

            $table->unique(['hit_date', 'bot', 'path'], 'ai_hits_uni');
            $table->index(['hit_date', 'bot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_crawler_hits');
    }
};
