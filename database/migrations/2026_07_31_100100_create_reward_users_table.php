<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 리워드 참여자(design-01 §2-1 farm_users → 매체 스코프 reward_users, C6·C14·C16 반영).
 * 일 한도·쿨다운·포인트 상한을 이 한 행의 원자 UPDATE 로 판정한다(핫패스가 로그를 읽지 않는다).
 * cooldown_until 인덱스는 만들지 않는다(C14 — 조회가 항상 PK/키 직격, 쓰기 증폭 방지).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('media_id')->constrained('reward_media')->cascadeOnDelete();
            $t->string('user_key_hash', 64);               // hash('sha256', x-user-key) — 평문 미저장
            $t->string('key_type', 8)->default('anon');    // anon | toss
            $t->text('anon_key_enc')->nullable();          // 지급 재시도용 익명키(encrypted cast). 지급 확정 시 NULL
            $t->string('status', 12)->default('active');   // active | blocked
            $t->string('blocked_reason', 120)->nullable();
            $t->date('today_date')->nullable();            // today_count/attempts 의 기준 농장일(KST 06:00 축)
            $t->unsignedTinyInteger('today_count')->default(0);     // 오늘 참여(확정) 횟수 — 일 한도(수십)로 상한
            $t->unsignedSmallInteger('today_attempts')->default(0); // 오늘 시도(오답·거절 포함) — C16.
            // 시도는 한도 초과 후에도 계속 증가하므로 tinyint(255)면 연타로 범람한다(strict 모드 = 로그 유실)
            $t->timestamp('cooldown_until')->nullable();   // 쿨다운 만료 시각
            $t->timestamp('last_submit_at')->nullable();   // 제출 간격(3초) 게이트 — C16
            $t->timestamp('last_participated_at')->nullable();
            $t->unsignedInteger('total_participations')->default(0);
            $t->unsignedInteger('accrued_points')->default(0);      // 적립 누적(미지급 포함) — 상한 게이트
            $t->unsignedInteger('paid_points')->default(0);         // 지급 확정 누적
            $t->unsignedSmallInteger('harvest_count')->default(0);
            $t->string('daily_ip', 45)->nullable();        // 마지막 참여 IP(어뷰징 클러스터 탐지) — C14
            $t->boolean('cooldown_notify')->default(false); // 쿨타임 종료 알림 수신(server-api-spec §/me/state)
            $t->timestamp('last_seen_at')->nullable();     // 1분 단위로만 갱신
            $t->timestamps();

            $t->unique(['media_id', 'user_key_hash'], 'ru_key');
            $t->index(['status', 'id'], 'ru_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_users');
    }
};
