<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * crm 메뉴 이식 보강:
 *  - is_group : 컨테이너(대/중분류) vs 페이지 항목 구분 (crm의 group/item 테이블 분리를 통합)
 *  - target   : 항목 링크 새창(_blank) 여부 (crm item_target)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->boolean('is_group')->default(false)->after('area');
            $table->string('target', 10)->default('')->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->dropColumn(['is_group', 'target']);
        });
    }
};
