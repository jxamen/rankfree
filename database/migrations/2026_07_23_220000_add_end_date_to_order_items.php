<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 세부주문 종료일(2026-07-23) — 회차가 하루가 아니라 기간(시작~종료)일 수 있다. 기본은 시작일과 동일(1일). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_order_items', function (Blueprint $t) {
            $t->date('end_date')->nullable()->after('work_date');
        });
        DB::table('marketing_order_items')->whereNull('end_date')->update(['end_date' => DB::raw('work_date')]);
    }

    public function down(): void
    {
        Schema::table('marketing_order_items', function (Blueprint $t) {
            $t->dropColumn('end_date');
        });
    }
};
