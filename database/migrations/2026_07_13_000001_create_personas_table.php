<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 커뮤니티 페르소나 — 자동으로 글/댓글/좋아요를 남기는 가상 활동자. 어드민에서 세부 설정. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personas', function (Blueprint $table) {
            $table->id();
            // 프로필
            $table->string('nickname', 40)->unique();
            $table->string('avatar_color', 9)->nullable();   // 아바타 배경색(#RRGGBB)
            $table->string('bio', 200)->nullable();          // 한줄 소개
            // 인구통계
            $table->unsignedTinyInteger('age')->nullable();
            $table->string('gender', 10)->nullable();        // male | female | none
            $table->string('region', 40)->nullable();
            // 성향
            $table->json('interests')->nullable();           // ["맛집","카페",...]
            $table->string('tone', 20)->default('friendly'); // friendly | expert | humor | chic | blunt
            $table->unsignedTinyInteger('emoji_level')->default(1); // 0 없음 · 1 보통 · 2 자주
            $table->string('post_length', 10)->default('mid'); // short | mid | long
            // 활동 설정
            $table->string('activity_level', 10)->default('normal'); // active | normal | rare
            $table->unsignedTinyInteger('post_weight')->default(3);    // 0~10 상대 빈도
            $table->unsignedTinyInteger('comment_weight')->default(6);
            $table->unsignedTinyInteger('like_weight')->default(8);
            $table->json('active_hours')->nullable();        // ["morning","evening"] 주 활동 시간대
            $table->json('preferred_categories')->nullable(); // [category_id,...] 선호 게시판
            // 상태
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_active')->default(true);   // 자동활동 on/off
            $table->timestamp('joined_at')->nullable();      // 자연스러운 가입일(과거)
            $table->unsignedInteger('posts_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->timestamp('last_acted_at')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'auto_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personas');
    }
};
