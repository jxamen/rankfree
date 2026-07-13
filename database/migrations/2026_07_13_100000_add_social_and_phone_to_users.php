<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 소셜 로그인(google/naver/kakao) + 전화번호 인증 컬럼 추가.
 * - phone/phone_verified_at: 가입 시 SMS 인증(알리고) 완료 저장
 * - provider/provider_id: 소셜 계정 식별(동일 이메일이면 기존 계정에 연결)
 * - password: 소셜 전용 가입은 비밀번호가 없으므로 nullable
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('email');
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
            $table->string('provider', 20)->nullable()->after('phone_verified_at');
            $table->string('provider_id')->nullable()->after('provider');
            $table->index(['provider', 'provider_id']);
        });

        // 소셜 전용 가입은 비밀번호가 없다 → nullable
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['provider', 'provider_id']);
            $table->dropColumn(['phone', 'phone_verified_at', 'provider', 'provider_id']);
        });
    }
};
