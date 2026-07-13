<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 스마트플레이스 계정 — 플레이스(지도/순위) URL 저장. 등록 시 PC map URL 도 m.place 로 정규화해 보관. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smartplace_accounts', function (Blueprint $table) {
            $table->string('place_url', 300)->nullable()->after('place_id'); // m.place.naver.com/{업종}/{placeId}
        });
    }

    public function down(): void
    {
        Schema::table('smartplace_accounts', function (Blueprint $table) {
            $table->dropColumn('place_url');
        });
    }
};
