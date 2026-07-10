<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 크롬 확장 등 외부 클라이언트용 API 토큰 — Sanctum 미사용, 자체 경량 토큰. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ext_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60)->default('chrome-extension');
            $table->string('token', 64)->unique(); // sha256(평문 토큰)
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_tokens');
    }
};
