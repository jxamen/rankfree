<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 리워드 매체(design-04 §2) — 미니앱·벤더 API 등 참여 채널의 앵커.
 * 매체별 환경설정(settings)·전용 배분 vendor_id(공유 풀 매체는 NULL)·지급 단가를 여기서 관리한다.
 * 벤더 ID 를 .env 에 두지 않는다(HANDOFF §7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_media', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 40)->unique();              // 라우트·설정 조회 키 (quiz-farm)
            $t->string('name', 120);
            $t->string('type', 20);                        // miniapp | vendor_api
            $t->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete(); // 전용 배분 매체만(design-04 §2-1)
            $t->foreignId('api_user_id')->nullable();      // vendor_api: API 키 소유 회원(users)
            $t->unsignedInteger('rate_limit_rps')->default(100);
            $t->unsignedInteger('payout_unit_price')->default(0);   // 참여 1건당 매체 지급 단가(원)
            $t->string('verify_mode', 20)->default('server');       // server | vendor
            $t->json('settings')->nullable();              // 매체별 환경설정(쿨타임·하루횟수 등 — config('reward.defaults') 오버라이드)
            $t->boolean('is_active')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_media');
    }
};
