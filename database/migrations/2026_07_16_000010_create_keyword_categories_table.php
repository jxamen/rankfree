<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 키워드 콘텐츠 허브 — 카테고리(플레이스/쇼핑, 2계층). 시드 키워드에서 후보를 수집한다. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_categories', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->default('shopping'); // place|shopping
            $table->foreignId('parent_id')->nullable()->constrained('keyword_categories')->nullOnDelete();
            $table->string('name', 80);
            $table->string('slug', 120)->unique();           // /keywords/{slug} (Phase 2)
            $table->string('description', 300)->nullable();
            $table->json('seed_keywords')->nullable();       // 수집 시드 키워드 목록
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('collected_at')->nullable();   // hub:collect 로테이션 커서
            $table->timestamps();

            $table->index(['type', 'is_active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_categories');
    }
};
