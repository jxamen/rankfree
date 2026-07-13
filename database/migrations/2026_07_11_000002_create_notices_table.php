<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 공지사항 — 관리자 등록, 콘솔 대시보드/고객센터 노출. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $t) {
            $t->id();
            $t->string('category', 40)->default('일반');   // 일반/업데이트/점검/이벤트
            $t->string('title');
            $t->longText('body');                          // WYSIWYG HTML
            $t->boolean('is_pinned')->default(false);      // 상단 고정
            $t->boolean('is_published')->default(true);
            $t->timestamp('published_at')->nullable();
            $t->unsignedInteger('views')->default(0);
            $t->timestamps();
            $t->index(['is_published', 'is_pinned']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
    }
};
