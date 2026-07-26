<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 업체별 랜딩 URL 설정 — 쇼핑 주문용(2026-07-25). 거래처마다 주문 받는 형태가 달라 업체 단위로 관리한다.
        // ⚠️ 플레이스 주문은 방식이 다를 수 있어 컬럼을 shop_ 로 명시한다(필요해지면 place_* 를 따로 추가).
        //  - shop_link_mode  : 랜딩 URL 배정 방식. group(분석 링크 순환) / param(파라미터 값 변경) / fixed(등록 URL 순서대로)
        //  - shop_url_patterns: 업체에 넘길 URL·형식 목록(JSON 배열, 입력 순서 = 사용 순서). 운영자가 직접 입력·관리.
        //  - shop_param_keys  : 파라미터 값 변경 방식일 때 "어떤 파라미터를 바꾸는지" 이름 목록(JSON 배열). 설정 기록용.
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('shop_link_mode', 20)->default('group')->after('weekend_batch_dispatch');
            $table->text('shop_url_patterns')->nullable()->after('shop_link_mode');
            $table->text('shop_param_keys')->nullable()->after('shop_url_patterns');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['shop_link_mode', 'shop_url_patterns', 'shop_param_keys']);
        });
    }
};
