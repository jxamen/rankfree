<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** 슬롯 공유 토큰 — 비로그인 공개 리포트(/r/{token})용. 기존 행은 백필. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('place_rank_slots', function (Blueprint $table) {
            $table->string('share_token', 64)->nullable()->unique()->after('label');
        });

        foreach (DB::table('place_rank_slots')->whereNull('share_token')->pluck('id') as $id) {
            DB::table('place_rank_slots')->where('id', $id)->update(['share_token' => Str::random(32)]);
        }
    }

    public function down(): void
    {
        Schema::table('place_rank_slots', function (Blueprint $table) {
            $table->dropColumn('share_token');
        });
    }
};
