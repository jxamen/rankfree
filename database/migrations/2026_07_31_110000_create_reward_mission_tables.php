<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 리워드 미션 미러(design-01 §2-3~2-5, 컬럼명은 C10 확정안) —
 * 세부주문서(marketing_order_items)를 참여 채널용 미션으로 미러링한다. 원본 테이블은 건드리지 않는다.
 * answer(정답)는 어떤 API 응답에도 포함 금지.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_missions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_item_id');       // marketing_order_items.id — FK 없음(원본 무간섭)
            $t->unsignedBigInteger('order_id');            // 스냅샷
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('advertiser_user_id')->nullable();  // marketing_orders.user_id
            $t->unsignedBigInteger('vendor_id')->nullable();           // 정산 귀속 스냅샷
            $t->unsignedSmallInteger('day_no')->default(0);            // 회차
            $t->string('status', 12)->default('draft');    // draft/active/paused/ended/canceled — 원본 status 와 별개 축
            $t->date('starts_on');                         // = moi.work_date
            $t->date('ends_on');                           // = moi.end_date(포함)
            $t->unsignedInteger('daily_quota')->default(0);            // 일 주문횟수 (= moi.quantity, per_day 확정 — HANDOFF §7)
            $t->unsignedInteger('total_quota')->default(0);            // 전체 수량 (= quantity × 기간일수)
            $t->unsignedInteger('total_used')->default(0);             // 전체 소진(참여 확정 시 원자 UPDATE)
            $t->json('slot_ratios')->nullable();           // 시간구간 배분 override(비면 config 기본)
            $t->decimal('unit_revenue', 12, 2)->default(0);            // 청구 단가 = total_price ÷ Σquantity(전 벤더) — C9
            $t->unsignedSmallInteger('payout_point')->default(0);      // 참여당 사용자 적립 포인트(운영자 입력)
            $t->unsignedSmallInteger('exposure_weight')->default(100); // 노출 가중
            $t->string('title', 80)->default('');
            $t->string('description', 200)->default('');
            $t->string('kind', 12)->default('external');   // internal/external/attendance
            $t->string('shop_name', 150)->nullable();
            $t->string('product_title', 200)->nullable();
            $t->unsignedInteger('product_price')->nullable();
            $t->string('product_image_url', 500)->nullable();
            $t->string('product_emoji', 8)->nullable();
            $t->string('keyword', 120)->nullable();
            $t->json('tags')->nullable();                  // 해시태그 목록(채점용, spi.seller_tags 스냅샷) — 응답 노출 금지
            $t->string('landing_url', 500)->nullable();    // = moi.short_url
            $t->string('product_url', 500)->nullable();    // landing_url 폴백
            $t->json('guide')->nullable();                 // 참여 방법 문자열 배열(운영자 입력)
            $t->string('question', 200)->nullable();
            $t->string('placeholder', 60)->nullable();
            $t->string('answer', 120)->nullable();         // 정답 — 응답 포함 절대 금지
            $t->string('answer_type', 8)->default('number');           // number/text
            $t->unsignedTinyInteger('tolerance_percent')->nullable();  // 숫자 오차 허용 %
            $t->string('reward_item', 12)->default('water');
            $t->unsignedTinyInteger('reward_count')->default(1);
            $t->unsignedTinyInteger('per_user_limit')->default(1);        // 동일 사용자 기간 누적 상한
            $t->unsignedTinyInteger('per_user_daily_limit')->default(1);  // 동일 사용자 1일 상한
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamp('synced_at')->nullable();        // 마지막 동기화
            $t->timestamps();

            $t->unique('order_item_id', 'rms_item');                       // 동기화 upsert 키
            $t->index(['status', 'starts_on', 'ends_on'], 'rms_window');   // 노출 후보 배치 조회
            $t->index(['order_id', 'day_no'], 'rms_order');                // 세부주문서 역참조(어드민)
        });

        // 미션×일 한도 게이트. 대리키 id 없음 — 참여 확정의 원자 UPDATE 가 클러스터드 PK(mission_id, stat_date)를
        // 직격해야 하고, 세컨더리 unique 경유는 랜덤 IO 1회가 더 든다(design-01 §2-4. 관례 예외 근거).
        Schema::create('reward_mission_daily_counters', function (Blueprint $t) {
            $t->unsignedBigInteger('mission_id');
            $t->date('stat_date');                         // KST 농장일
            $t->unsignedInteger('daily_quota')->default(0);            // 그날 한도 스냅샷
            $t->json('slot_ratios')->nullable();           // 그날 구간 배분 스냅샷
            $t->unsignedInteger('used')->default(0);       // 일별 소진량(원자 UPDATE 대상)
            $t->unsignedInteger('overflow_count')->default(0);         // 한도 초과 확정 건(청구 불가 손실)
            $t->unsignedInteger('slot_overflow_count')->default(0);    // 구간 상한 초과 시도(C1 관측용)
            $t->timestamp('first_used_at')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();

            $t->primary(['mission_id', 'stat_date']);
            $t->index(['stat_date', 'mission_id'], 'rmdc_date');       // 일별 잔여 현황·롤업
        });

        // 목록 응답 캐시 원본 — 웹서버 여러 대가 같은 스냅샷을 보게 하는 DB 원장(design-01 §2-5)
        Schema::create('reward_mission_snapshots', function (Blueprint $t) {
            $t->string('slot_key', 40)->primary();         // 'active' (필요 시 'active:slot3')
            $t->mediumText('payload');                     // 미션 배열 JSON(정답 계열 제외)
            $t->unsignedSmallInteger('item_count')->default(0);
            $t->date('built_for_day')->nullable();         // 이 payload 가 기준으로 삼은 농장일 — 06:00 경계에서 낡은 스냅샷 판별
            $t->timestamp('built_at');
            $t->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_mission_snapshots');
        Schema::dropIfExists('reward_mission_daily_counters');
        Schema::dropIfExists('reward_missions');
    }
};
