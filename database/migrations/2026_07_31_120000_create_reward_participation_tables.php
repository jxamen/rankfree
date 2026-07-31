<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 리워드 참여 확정 경로(Phase 3 — design-01 §2-6~2-8, C18·C27·C8) —
 * 재배 회차(부채 원장)·사용자×미션 카운터·참여 로그(append-only, 파티션은 Phase 9).
 * reward_user_mission_counters 와 참여 로그에 updated_at 이 없는 것은 관례 예외다
 * (쓰기 1회 절감 × 대량, design-01 §2-6·2-7).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 재배 회차 + 부채 원장. 하루치 성장은 day_mask 비트 원자 UPDATE 한 문장(설계 §2-8)
        Schema::create('farm_plantings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reward_user_id')->constrained('reward_users')->cascadeOnDelete();
            $t->unsignedTinyInteger('plot_index');         // 0~2
            $t->unsignedSmallInteger('round_no')->default(1);          // 같은 밭의 몇 번째 재배
            $t->string('crop_id', 20);                     // farm_crops.code
            $t->unsignedTinyInteger('required_days')->default(7);      // 심을 때 스냅샷
            $t->unsignedSmallInteger('day_mask')->default(0);          // 일차 비트마스크(1일차=bit0)
            $t->unsignedTinyInteger('completed_days')->default(0);     // BIT_COUNT(day_mask) 동치 캐시
            $t->string('status', 12)->default('growing');  // growing/ready/harvested/abandoned
            $t->date('planted_on');
            $t->date('last_tended_on')->nullable();        // 하루 1회 게이트
            $t->unsignedInteger('accrued_points')->default(0);         // 확정 부채(참여 적립 누계)
            $t->unsignedInteger('reward_points')->default(0);          // 조건부 부채(수확 보너스 스냅샷) — C27
            $t->timestamp('harvested_at')->nullable();
            $t->unsignedBigInteger('ledger_id')->nullable();
            $t->timestamps();

            $t->unique(['reward_user_id', 'plot_index', 'round_no'], 'fpg_uni');
            $t->index(['reward_user_id', 'status'], 'fpg_user');       // /me/state 밭 조회
            $t->index(['status', 'planted_on'], 'fpg_debt');           // 미지급 부채 집계
        });

        // 사용자×미션 누적 — per_user_limit / per_user_daily_limit 게이트(2-step 원자 갱신)
        Schema::create('reward_user_mission_counters', function (Blueprint $t) {
            $t->unsignedBigInteger('reward_user_id');
            $t->unsignedBigInteger('mission_id');
            $t->unsignedSmallInteger('done_count')->default(0);        // 기간 누적 참여
            $t->unsignedTinyInteger('today_count')->default(0);        // 그 미션 오늘 참여
            $t->date('last_done_on');                      // today_count 의 기준일
            $t->timestamp('created_at');                   // updated_at 없음 — 관례 예외(상단 docblock)

            $t->primary(['reward_user_id', 'mission_id']);             // /missions 제외 필터가 PK prefix scan
            $t->index(['mission_id', 'reward_user_id'], 'rumc_mission'); // 미션 종료 후 청크 삭제
        });

        // 참여 로그 — append-only. 정산·어뷰징 조사의 원천. 월 파티션은 Phase 9(그 전까지 일반 테이블)
        Schema::create('reward_participation_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('media_id')->default(0);            // 매체 구분(design-04)
            $t->unsignedInteger('stat_month');             // YYYYMM — Phase 9 파티션 키 선반영
            $t->date('stat_date');                         // 참여 농장일(KST)
            $t->unsignedTinyInteger('slot_no')->default(0);            // 시간구간 0~6
            $t->unsignedBigInteger('reward_user_id');      // FK 없음(파티션 예정 테이블 관례)
            $t->unsignedBigInteger('mission_id');          // FK 없음
            $t->unsignedBigInteger('order_item_id')->default(0);       // 정산 귀속
            $t->unsignedBigInteger('order_id')->default(0);            // 정산 귀속
            $t->unsignedBigInteger('vendor_id')->nullable();           // 정산 귀속
            $t->unsignedBigInteger('planting_id')->nullable();
            $t->unsignedTinyInteger('plot_index')->nullable();         // 복구용 자기기술
            $t->unsignedTinyInteger('day_no')->nullable();             // 재배 1~7일차 복구용
            $t->unsignedSmallInteger('round_no')->nullable();
            $t->string('crop_id', 20)->nullable();
            $t->string('result', 10);                      // correct/wrong/rejected
            $t->string('reject_reason', 24)->nullable();
            $t->string('answer_norm', 64)->nullable();     // 정규화 입력. correct 면 NULL(정답과 동일)
            $t->decimal('unit_revenue', 12, 2)->default(0);            // 단가 스냅샷(2중 스냅샷 — C9)
            $t->unsignedSmallInteger('payout_point')->default(0);      // 적립 포인트 스냅샷
            $t->unsignedInteger('seq_in_day')->default(0);             // 그날 그 미션 전역 순번(C1·billable 판정 입력)
            $t->unsignedInteger('daily_quota')->default(0);            // 그 시점 한도 스냅샷
            $t->boolean('is_overflow')->default(false);    // seq_in_day > daily_quota (absorb 정책에서만 발생)
            $t->boolean('slot_overflow')->default(false);  // 구간 상한 초과 확정(C8) — 분산 튜닝 입력
            $t->string('ip', 45)->nullable();              // 어뷰징 조사
            $t->timestamp('created_at');                   // updated_at 없음 — append-only 관례

            $t->index(['stat_date', 'mission_id'], 'rpl_date');        // 일 마감 롤업
            $t->index(['reward_user_id', 'id'], 'rpl_user');           // CS·어뷰징 사용자 이력
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_participation_logs');
        Schema::dropIfExists('reward_user_mission_counters');
        Schema::dropIfExists('farm_plantings');
    }
};
