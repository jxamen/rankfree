<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 커뮤니티 게시글 — 작성자는 페르소나 또는 실사용자(author_type). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('community_categories')->cascadeOnDelete();
            $table->string('author_type', 10);               // persona | user
            $table->foreignId('persona_id')->nullable()->constrained('personas')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 150);
            $table->text('body');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();
            $table->index(['category_id', 'created_at']);
            $table->index(['author_type', 'persona_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_posts');
    }
};
