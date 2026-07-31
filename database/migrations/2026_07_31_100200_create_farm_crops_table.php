<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 작물 마스터(design-01 §2-2) — 퀴즈농장(miniapp) 게임 도메인이라 farm 이름 유지(HANDOFF §7 네이밍).
 * points(수확 보너스)는 어드민에서 운영 관리하고, 심은 시점에 스냅샷되어 소급되지 않는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farm_crops', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20);                        // lettuce/carrot/… — 클라 CROPS[].id 와 1:1
            $t->string('name', 40);
            $t->string('emoji', 8);
            $t->unsignedTinyInteger('days')->default(7);   // 재배 일수(CROP_DAYS)
            $t->unsignedInteger('points')->default(0);     // 수확 보너스
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->unique('code', 'fc_code');
            $t->index(['is_active', 'sort_order'], 'fc_sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_crops');
    }
};
