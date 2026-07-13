<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 스마트플레이스 계정 — 쿠키 붙여넣기 → 네이버 아이디/비밀번호 자동 로그인 방식으로 전환.
 *  cookie 는 자동 로그인으로 채워지는 세션 캐시로 유지, 자격(naver_id/naver_pw)을 추가. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smartplace_accounts', function (Blueprint $table) {
            $table->string('naver_id', 100)->nullable()->after('site_id');
            $table->text('naver_pw')->nullable()->after('naver_id'); // encrypted cast
            $table->timestamp('logged_in_at')->nullable()->after('last_collected_at'); // 마지막 자동 로그인 성공 시각
        });
    }

    public function down(): void
    {
        Schema::table('smartplace_accounts', function (Blueprint $table) {
            $table->dropColumn(['naver_id', 'naver_pw', 'logged_in_at']);
        });
    }
};
