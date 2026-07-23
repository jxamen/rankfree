<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * menus.url 200 → 500 (2026-07-23) — 외부 링크 메뉴 지원.
 * 크롬 웹스토어처럼 한글이 %인코딩된 URL은 200자를 훌쩍 넘는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->string('url', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->string('url', 200)->nullable()->change();
        });
    }
};
