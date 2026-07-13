<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 메뉴 × 주체(등급/역할)별 월간 이용 횟수 제한. -1=무제한, 0=미제공, N=월 N회. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_permissions', function (Blueprint $table) {
            $table->integer('monthly_limit')->default(-1)->after('can_delete');
        });
    }

    public function down(): void
    {
        Schema::table('menu_permissions', function (Blueprint $table) {
            $table->dropColumn('monthly_limit');
        });
    }
};
