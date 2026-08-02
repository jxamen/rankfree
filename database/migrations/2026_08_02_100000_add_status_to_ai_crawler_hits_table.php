<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 응답 상태코드 기록(2026-08-02) — UA 를 위조한 자격증명 스캐너(/.env·/.aws/credentials 등)가
        // GPTBot 으로 집계돼 "AI 가 읽어간 문서" 상위에 올라오던 문제. 404 는 읽힌 게 아니다.
        // 기존 행은 상태를 알 수 없어 200 으로 남는다(과거 구간 오염은 소급 교정 불가).
        Schema::table('ai_crawler_hits', function (Blueprint $table) {
            $table->unsignedSmallInteger('status')->default(200)->after('path');
        });

        // 같은 경로라도 200 과 404 는 다른 사건이므로 유일키에 포함한다
        Schema::table('ai_crawler_hits', function (Blueprint $table) {
            $table->dropUnique('ai_hits_uni');
            $table->unique(['hit_date', 'bot', 'path', 'status'], 'ai_hits_uni');
        });
    }

    public function down(): void
    {
        Schema::table('ai_crawler_hits', function (Blueprint $table) {
            $table->dropUnique('ai_hits_uni');
        });

        Schema::table('ai_crawler_hits', function (Blueprint $table) {
            $table->dropColumn('status');
            $table->unique(['hit_date', 'bot', 'path'], 'ai_hits_uni');
        });
    }
};
