<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 커뮤니티 게시판 카테고리 (자유게시판 · 마케팅 노하우 · 플레이스 후기 · 쇼핑 팁 · 질문답변 등). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name', 60);
            $table->string('description', 200)->nullable();
            $table->string('icon', 16)->nullable();          // 이모지 아이콘
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_categories');
    }
};
