<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 메뉴 SEO 브랜드 표기 한글화 — 검색결과/브라우저 탭 노출용 메타의 'rankfree' → '랭크프리'.
 * (로고·헤더의 브랜드 마크는 별개. SEO 텍스트만 한글 검색 친화적으로.)
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['meta_title', 'meta_description', 'meta_keywords'] as $col) {
            DB::table('menus')->where($col, 'like', '%rankfree%')
                ->update([$col => DB::raw("REPLACE($col, 'rankfree', '랭크프리')")]);
        }
    }

    public function down(): void
    {
        foreach (['meta_title', 'meta_description', 'meta_keywords'] as $col) {
            DB::table('menus')->where($col, 'like', '%랭크프리%')
                ->update([$col => DB::raw("REPLACE($col, '랭크프리', 'rankfree')")]);
        }
    }
};
