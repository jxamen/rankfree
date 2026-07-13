<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 외부 API 키 (발급·허용기간·일일 한도·허용 IP) + 일자별 사용량. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);                       // 용도 라벨
            $table->string('key_prefix', 16);                 // 목록 표시용 앞부분
            $table->string('key_hash', 64)->unique();         // sha256 — 원문은 저장하지 않음
            $table->json('scopes');                           // ['rank','compete','keyword']
            $table->text('allowed_ips')->nullable();          // 줄바꿈/쉼표 구분. 비우면 전체 허용
            $table->timestamp('expires_at')->nullable();      // 허용기간 (null=무기한)
            $table->unsignedInteger('daily_limit')->nullable(); // 일일 호출 한도 (null=무제한)
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('api_key_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_key_id')->constrained('api_keys')->cascadeOnDelete();
            $table->date('used_date');
            $table->unsignedInteger('count')->default(0);
            $table->unique(['api_key_id', 'used_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_key_usages');
        Schema::dropIfExists('api_keys');
    }
};
