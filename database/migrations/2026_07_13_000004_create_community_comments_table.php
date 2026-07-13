<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 커뮤니티 댓글 — 대댓글(parent_id) 지원. 작성자는 페르소나 또는 실사용자. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('community_posts')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('community_comments')->cascadeOnDelete();
            $table->string('author_type', 10);               // persona | user
            $table->foreignId('persona_id')->nullable()->constrained('personas')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->unsignedInteger('likes_count')->default(0);
            $table->timestamps();
            $table->index(['post_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_comments');
    }
};
