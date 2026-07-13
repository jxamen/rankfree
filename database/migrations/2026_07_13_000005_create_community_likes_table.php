<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 좋아요 — 글/댓글(likeable_type) × 페르소나/실사용자(liker_type). 조합 유니크로 중복 방지. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_likes', function (Blueprint $table) {
            $table->id();
            $table->string('likeable_type', 10);  // post | comment
            $table->unsignedBigInteger('likeable_id');
            $table->string('liker_type', 10);      // persona | user
            $table->unsignedBigInteger('liker_id');
            $table->timestamps();
            $table->unique(['likeable_type', 'likeable_id', 'liker_type', 'liker_id'], 'community_like_unique');
            $table->index(['likeable_type', 'likeable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_likes');
    }
};
