<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 글밥(소스) — 다른 커뮤니티에서 수집한 글감. 페르소나가 이걸 소재로 글/댓글을 변형해 사용한다.
 * kind: post(제목+본문 소재) | comment(댓글 소재).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_seeds', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10)->default('post');  // post | comment
            $table->foreignId('category_id')->nullable()->constrained('community_categories')->nullOnDelete();
            $table->string('title', 200)->nullable();      // post 소재 제목(선택)
            $table->text('body');                          // 소재 본문/댓글 텍스트
            $table->string('source', 80)->nullable();      // 출처 메모
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamps();
            $table->index(['kind', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_seeds');
    }
};
