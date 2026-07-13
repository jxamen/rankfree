<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 팝업 — 대시보드 진입 시 노출. 위치·크기·기간·닫기옵션 지정, 본문은 WYSIWYG. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('popups', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->longText('body');                          // WYSIWYG HTML
            $t->string('position', 20)->default('center'); // center/top-left/top-right/bottom-left/bottom-right
            $t->unsignedInteger('width')->default(420);
            $t->boolean('is_active')->default(true);
            $t->boolean('dismissible')->default(true);      // '오늘 하루 보지 않기' 허용
            $t->integer('sort_order')->default(0);
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->timestamps();
            $t->index(['is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('popups');
    }
};
