<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 마케팅 리드(상담 문의) — 분석 리포트에서 "순위 상승 문의하기" 등으로 남긴 연락처.
 * 비로그인 공개 공유 페이지에서도 접수 가능(user_id nullable). 조회는 슈퍼어드민만.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 80);
            $table->string('phone', 40);
            $table->string('keyword', 160)->nullable();    // 문의 대상 키워드(시장 분석 등)
            $table->string('source', 40)->default('other'); // 유입 지점: market_seasonal / market / product / keyword
            $table->string('interest', 80)->nullable();     // 관심 상품/서비스(예: 순위 상승 프로그램)
            $table->text('message')->nullable();
            $table->json('meta')->nullable();               // {peak_months, prep_months, strength, is_public}
            $table->string('status', 20)->default('new');   // new|contacted|done|spam
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('keyword');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_leads');
    }
};
