<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 홍보 배너 — 대시보드 노출(신규 상품/업체 홍보/프로모션). 기간·정렬·노출 제어. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->string('subtitle')->nullable();
            $t->text('body')->nullable();
            $t->string('image_url')->nullable();           // 배너 이미지(URL) 또는 색배경
            $t->string('link_url')->nullable();
            $t->string('link_label', 60)->nullable();
            $t->string('type', 20)->default('promo');      // product/company/promo
            $t->string('bg_color', 40)->nullable();        // 디자인 토큰/hex
            $t->string('text_color', 40)->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->timestamps();
            $t->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
