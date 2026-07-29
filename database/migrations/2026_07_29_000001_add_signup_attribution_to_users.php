<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 회원 가입 유입경로(어트리뷰션) — 어디서 보고 가입했는지 기록.
 * first-touch: 게스트 첫 방문의 referrer·utm·landing 을 쿠키에 담아 가입 시 저장(CaptureAttribution).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('signup_referrer', 500)->nullable()->after('referral_bonus_slots');
            $table->json('signup_utm')->nullable()->after('signup_referrer');
            $table->string('signup_landing', 500)->nullable()->after('signup_utm');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['signup_referrer', 'signup_utm', 'signup_landing']);
        });
    }
};
