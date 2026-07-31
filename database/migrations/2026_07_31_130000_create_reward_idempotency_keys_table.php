<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 벤더 참여 제출 멱등키(design-04 §4-4) — 재시도 폭풍에도 카운터가 두 번 줄지 않게 한다.
 * 수락된 제출만 저장하고, 같은 키 재시도에는 첫 응답을 duplicate 로 재반환한다.
 * Redis SETNX 1차 캐시는 Phase 4에서 앞단에 추가된다(DB unique 는 그대로 백스톱).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('media_id');
            $t->string('idem_key', 80);                    // 벤더 생성 UUID(Idempotency-Key 헤더)
            $t->unsignedBigInteger('participation_log_id');
            $t->json('response');                          // 첫 응답 스냅샷(재반환용)
            $t->timestamp('created_at');

            $t->unique(['media_id', 'idem_key'], 'rik_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_idempotency_keys');
    }
};
