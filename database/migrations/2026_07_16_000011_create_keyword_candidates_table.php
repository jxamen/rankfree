<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 키워드 콘텐츠 허브 — 키워드 후보(수집 → 승인 → 발행 파이프라인). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('keyword_categories')->cascadeOnDelete();
            $table->string('keyword', 120);
            $table->string('source', 20)->default('seed');   // seed|related|autocomplete|user
            $table->unsignedBigInteger('monthly_total')->nullable(); // 수집 시점 검색량(자동완성은 미상)
            $table->string('comp_idx', 20)->nullable();
            $table->string('status', 20)->default('pending'); // pending|approved|rejected|published
            $table->string('note', 200)->nullable();          // 보류·거부 사유 등
            $table->timestamps();

            $table->unique(['category_id', 'keyword']);
            $table->index(['status', 'monthly_total']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_candidates');
    }
};
