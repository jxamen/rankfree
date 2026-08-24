<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 제휴 매체 × 미션 유형별 지급 단가(design-04 §2-1) — 지출 계산의 입력.
 * 유형마다 매체에 줘야 하는 금액이 다르다(퀴즈 vs 출석 등). 유형별 행이 없으면
 * 매체 기본 단가(reward_media.payout_unit_price)로 폴백한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_media_payouts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('media_id')->constrained('reward_media')->cascadeOnDelete();
            $t->string('kind', 12);                         // reward_missions.kind (internal|external|attendance)
            $t->unsignedInteger('unit_price')->default(0);   // 그 유형 참여 1건당 매체 지급 단가(원)
            $t->timestamps();

            $t->unique(['media_id', 'kind'], 'rmp_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_media_payouts');
    }
};
