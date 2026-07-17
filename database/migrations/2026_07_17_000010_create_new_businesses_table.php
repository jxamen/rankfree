<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 신규 개업(인허가) — 지방행정 인허가 공공데이터 수집분 + 네이버 플레이스 매칭 결과.
 * 관리자 열람 전용(24_NEW_BUSINESS.md). 전화는 암호화 저장, 인허가일 +N일 경과분은 자동 파기.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('new_businesses', function (Blueprint $t) {
            $t->id();
            $t->string('source', 20)->default('seoul');     // 데이터 출처(seoul|data_go_kr)
            $t->string('svc', 30);                          // 원천 서비스명(LOCALDATA_072404)
            $t->string('svc_label', 40);                    // 업종 표시명(일반음식점)
            $t->string('mgt_no', 60);                       // 관리번호 — 원천 고유키
            $t->string('bplc_nm', 200);                     // 사업장명
            $t->string('uptae_nm', 60)->nullable();         // 업태(한식·커피숍…)
            $t->date('apv_perm_ymd')->nullable();           // 인허가일자
            $t->string('trd_state_nm', 20)->nullable();     // 영업상태(영업/정상·폐업)
            $t->text('site_tel')->nullable();               // 소재지전화 — 암호화 저장(개인정보 취급)
            $t->string('site_addr', 300)->nullable();       // 지번주소
            $t->string('road_addr', 300)->nullable();       // 도로명주소
            $t->string('sido', 20)->nullable();             // 파생 — 시/도(서울)
            $t->string('sgg', 40)->nullable();              // 파생 — 시/군/구(용산구)
            $t->string('emd', 40)->nullable();              // 파생 — 읍면동
            $t->string('update_gbn', 2)->nullable();        // 원천 갱신구분(I/U)
            $t->timestamp('src_updated_at')->nullable();    // 원천 갱신일시(UPDATEDT)
            $t->timestamp('collected_at')->nullable();      // 우리 수집 시각(수집 출처 이력)

            // 네이버 플레이스 매칭
            $t->string('place_id', 30)->nullable();
            $t->string('place_name', 200)->nullable();
            $t->string('place_cat', 30)->nullable();
            $t->string('place_status', 20)->default('pending'); // pending|found|not_found|blocked
            $t->timestamp('place_checked_at')->nullable();

            $t->timestamps();

            $t->unique(['source', 'mgt_no']);
            $t->index(['apv_perm_ymd']);
            $t->index(['sido', 'sgg']);
            $t->index(['place_status', 'apv_perm_ymd']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('new_businesses');
    }
};
