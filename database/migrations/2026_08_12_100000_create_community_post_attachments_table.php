<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 커뮤니티 글 첨부파일 — 등록은 운영자만, 다운로드는 글을 보는 누구나.
 * 실제 파일은 비공개 디스크(storage/app/private/community-attachments)에 두고
 * 다운로드 라우트로만 내려보낸다(공개 URL 없음 → 경로 추측으로 못 가져간다).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_post_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('community_posts')->cascadeOnDelete();
            $table->string('original_name', 191);   // 사용자에게 보여줄 원본 파일명
            $table->string('path', 191);            // 비공개 디스크 상대경로(랜덤 파일명)
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamps();
            $table->index('post_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_post_attachments');
    }
};
