<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 허브 문서 폐기 표시 — 저품질(월 조회수 <=10) 발행 문서를 '지운' 것으로 표시.
 * 페이지는 301(카테고리 허브)로 리다이렉트하고 사이트맵·추천에서 제외한다.
 * 하드 삭제 대신 소프트 폐기 — 리다이렉트 타깃(카테고리) 유지 + 되돌리기 가능(인덱싱 안전).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_searches', function (Blueprint $t) {
            $t->timestamp('retired_at')->nullable()->after('refreshed_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('keyword_searches', function (Blueprint $t) {
            $t->dropColumn('retired_at');
        });
    }
};
