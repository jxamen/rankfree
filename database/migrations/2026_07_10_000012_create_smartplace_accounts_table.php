<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 스마트플레이스 리포트 수집 계정 — crm ads/smartplace 이식. 쿠키·수집결과는 암호화 캐스트로 저장. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smartplace_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100)->default('');            // 업체명(표시용)
            $table->string('place_seq', 30);                       // 스마트플레이스 URL 의 /place/숫자
            $table->string('business_id', 30)->nullable();         // 예약(booking) 사업자 ID
            $table->string('place_id', 30)->nullable();            // 수집으로 확인되는 플레이스 ID
            $table->string('site_id', 100)->nullable();            // bizadvisor sp_xxx
            $table->string('sp_name', 150)->nullable();            // 수집으로 확인되는 업체명
            $table->string('category', 50)->nullable();            // 업종(블로그 리뷰 API 파라미터) — 비우면 자동 판별
            $table->text('cookie')->nullable();                    // 네이버 세션 쿠키 (encrypted cast)
            $table->longText('last_result')->nullable();           // 최근 수집 결과 JSON (encrypted cast — 예약고객 개인정보 포함)
            $table->string('last_status', 20)->default('');        // OK / FAIL
            $table->timestamp('last_collected_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smartplace_accounts');
    }
};
