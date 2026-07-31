<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 식별자 한도 카운터(design-04 §8) — IP·ADID 등으로 재참여 횟수를 제한한다.
 * 식별자 원문은 저장하지 않고 해시만 둔다(개인정보 최소화).
 * 참여 확정 트랜잭션 안에서 조건부 원자 UPDATE 로 소비한다 — 사전 COUNT 검사만으로는
 * 같은 IP 뒤 여러 사용자가 동시에 통과해 한도가 무력화된다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_identity_counters', function (Blueprint $t) {
            $t->unsignedBigInteger('media_id');
            $t->string('id_type', 8);                      // ip | adid (확장 가능)
            $t->string('id_hash', 64);                     // sha256(식별자) — 원문 미저장
            $t->string('scope', 8)->default('day');        // day | mission
            $t->string('scope_key', 32);                   // day: 농장일(YYYY-MM-DD) | mission: "{missionId}:{date}"
            $t->unsignedInteger('used')->default(0);       // 확정 참여 수
            $t->unsignedInteger('attempts')->default(0);   // 시도 수(오답·거절 포함) — 정답 브루트포스 탐지
            $t->timestamp('updated_at')->nullable();

            // 대리키 없음 — 참여 확정의 원자 UPDATE 가 PK 를 직격한다(reward_mission_daily_counters 와 동일 근거)
            $t->primary(['media_id', 'id_type', 'id_hash', 'scope', 'scope_key'], 'ric_key');
            $t->index(['scope_key', 'id_type'], 'ric_scope');   // 일별 정리·어뷰징 클러스터 조회
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_identity_counters');
    }
};
