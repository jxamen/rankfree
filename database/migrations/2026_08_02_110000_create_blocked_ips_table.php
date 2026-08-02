<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 취약점 탐침 IP 차단(2026-08-02) — /.env·/.aws/credentials 등을 훑는 IP 를 기록·차단한다.
        // 영구 차단이 아니라 blocked_until 까지만 — 스캐너는 IP 를 바꾸고, 공유 IP 오차단 피해를 시간으로 제한한다.
        Schema::create('blocked_ips', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->unique();      // IPv6 최대 45자
            $table->string('reason', 60);            // probe = 탐침 자동 차단
            $table->string('hit_path', 255);         // 차단을 유발한 경로(오차단 조사용)
            $table->unsignedInteger('hits')->default(1);
            $table->timestamp('blocked_until')->nullable();   // null = 무기한(수동 차단)
            $table->timestamps();

            $table->index('blocked_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_ips');
    }
};
