<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 매체별 미션 배분(design-04 §2-1) — "벤더1에 어떤 미션을 어떤 비율로 줄지".
 * 매체마다 처리 능력도 지급 단가도 다르다. 비싼 매체가 물량을 다 가져가면 마진이 무너지므로
 * 매체별 비율·상한을 걸 수 있어야 한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 배분 규칙 — 매체 × 대상(전체/유형/개별 미션). 좁은 범위가 우선한다(mission > kind > all)
        Schema::create('reward_media_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('media_id')->constrained('reward_media')->cascadeOnDelete();
            $t->string('scope', 10)->default('all');       // all | kind | mission
            $t->string('scope_key', 40)->default('');      // kind: 미션 유형 코드 | mission: 미션 id
            $t->unsignedTinyInteger('ratio')->nullable();  // 일 수량 대비 % (null = 제한 없음 = 공유 풀)
            $t->unsignedInteger('max_per_day')->nullable();// 절대 상한(일). ratio 와 함께 걸면 더 작은 값
            $t->unsignedInteger('min_per_day')->default(0);// 최소 보장(참고용 — 현재 노출 우선순위에만 반영)
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->unique(['media_id', 'scope', 'scope_key'], 'rma_target');
        });

        // 미션×매체×일 카운터 — 배분 상한의 집행 지점.
        // 대리키 없음: 참여 확정의 원자 UPDATE 가 PK 를 직격해야 한다(reward_mission_daily_counters 와 동일 근거).
        Schema::create('reward_mission_media_counters', function (Blueprint $t) {
            $t->unsignedBigInteger('mission_id');
            $t->unsignedBigInteger('media_id');
            $t->date('stat_date');                         // KST 농장일
            $t->unsignedInteger('cap')->default(0);        // 그날 그 매체 상한(0 = 배분 없음 → 참여 불가)
            $t->unsignedInteger('used')->default(0);
            $t->timestamp('updated_at')->nullable();

            $t->primary(['mission_id', 'media_id', 'stat_date'], 'rmmc_key');
            $t->index(['stat_date', 'media_id'], 'rmmc_date');   // 매체별 일 소진 현황
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_mission_media_counters');
        Schema::dropIfExists('reward_media_allocations');
    }
};
