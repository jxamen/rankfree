<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * checked_date 정규화 — 'date' 캐스트가 sqlite 에 'Y-m-d H:i:s' 로 저장한 값을
 * 날짜만('Y-m-d')으로 잘라 updateOrCreate 조회와 유니크 제약이 맞물리게 한다.
 * (MySQL DATE 컬럼은 애초에 날짜만 저장하므로 대상 아님)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("update place_rank_records set checked_date = substr(checked_date, 1, 10)");
        }
    }

    public function down(): void
    {
        // 정규화는 되돌릴 필요 없음
    }
};
