<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 셀러력 분석 공개 공유 링크용 토큰 — 비로그인 열람(/sp/{token}). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_power_analyses', function (Blueprint $table) {
            $table->string('share_token', 40)->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('seller_power_analyses', fn (Blueprint $t) => $t->dropColumn('share_token'));
    }
};
