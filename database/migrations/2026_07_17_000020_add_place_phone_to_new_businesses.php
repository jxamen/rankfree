<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 신규 개업 — 플레이스 등록 전화번호(24).
 * 인허가 원천의 SITETEL 은 대부분 비어 있어(실측) 번호를 보려면 플레이스에서 가져와야 한다.
 * ⚠️ 번호는 개인정보로 취급 — 모델에서 encrypted 캐스트. 광고 발송 금지(정보통신망법 제50조).
 */
return new class extends Migration
{
    public function up(): void
    {
        // place_id 는 생성 마이그레이션에 이미 있다(그동안 공식 API 엔 id 가 없어 비어 있었을 뿐)
        Schema::table('new_businesses', function (Blueprint $t) {
            $t->text('place_phone')->nullable()->after('place_cat');              // 암호화 저장이라 text
            $t->string('place_phone_type', 10)->nullable()->after('place_phone'); // virtual(0507 안심번호) | normal
        });
    }

    public function down(): void
    {
        Schema::table('new_businesses', function (Blueprint $t) {
            $t->dropColumn(['place_phone', 'place_phone_type']);
        });
    }
};
