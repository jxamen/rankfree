<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 네이버 검색광고 웹 콘솔 세션 쿠키(암호화) 저장 — 단일 레코드(id=1). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('naver_ad_sessions', function (Blueprint $table) {
            $table->id();
            $table->text('cookies')->nullable();          // Crypt 암호화된 쿠키 문자열
            $table->string('customer_id', 30)->nullable();
            $table->string('status', 20)->default('empty'); // empty | active | stale
            $table->timestamp('logged_in_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('naver_ad_sessions');
    }
};
