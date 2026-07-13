<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FAQ — 기능별 자주 묻는 질문. 콘솔 고객센터에서 검색 가능. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faqs', function (Blueprint $t) {
            $t->id();
            $t->string('category', 40)->default('일반');   // 기능별 카테고리
            $t->string('question');
            $t->longText('answer');                        // WYSIWYG HTML
            $t->integer('sort_order')->default(0);
            $t->boolean('is_published')->default(true);
            $t->unsignedInteger('views')->default(0);
            $t->timestamps();
            $t->index(['is_published', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
    }
};
