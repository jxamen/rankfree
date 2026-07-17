<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 후보 키워드 검색량 갱신 시각 — 관리자 키워드 탐색이 화면에 뜬 키워드의 검색량을 자동 갱신할 때
 * "주 1회만" 판정하는 기준. null 이면 아직 조회한 적 없음.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_candidates', function (Blueprint $t) {
            $t->timestamp('volume_checked_at')->nullable()->after('comp_idx')->index();
        });
    }

    public function down(): void
    {
        Schema::table('keyword_candidates', function (Blueprint $t) {
            $t->dropIndex(['volume_checked_at']);
            $t->dropColumn('volume_checked_at');
        });
    }
};
