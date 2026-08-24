<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 제휴 매체 전용 API 키(2026-08-24) — 고객용 회원 API 키(api_keys)와 완전히 분리한다.
 *
 * 종전: 매체가 회원인 척해야 했다(reward_media.api_user_id → users → api_keys.scopes=mission).
 * 매체를 등록해도 호출 수단이 안 생기고, 회원↔매체 매핑이 어느 화면에도 드러나지 않았다.
 * 이제 **매체 등록 시 그 매체의 키가 생성**되고, 키가 곧 인증 주체다 → api_user_id 우회는 제거.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reward_media', function (Blueprint $t) {
            $t->string('api_key_prefix', 16)->nullable()->after('type');      // 화면 표시용 앞자리(rkm_…)
            $t->string('api_key_hash', 64)->nullable()->unique()->after('api_key_prefix');
            $t->text('api_key_encrypted')->nullable()->after('api_key_hash'); // 운영자 재전달용 원문(암호화)
            $t->timestamp('api_key_last_used_at')->nullable()->after('api_key_encrypted');
        });

        Schema::table('reward_media', function (Blueprint $t) {
            $t->dropColumn('api_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('reward_media', function (Blueprint $t) {
            $t->unsignedBigInteger('api_user_id')->nullable()->after('vendor_id');
        });

        Schema::table('reward_media', function (Blueprint $t) {
            $t->dropUnique(['api_key_hash']);
            $t->dropColumn(['api_key_prefix', 'api_key_hash', 'api_key_encrypted', 'api_key_last_used_at']);
        });
    }
};
