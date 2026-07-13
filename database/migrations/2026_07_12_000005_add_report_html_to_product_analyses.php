<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 상품 분석 저장 시 확장(product.js)이 렌더한 리포트 HTML을 함께 보관 →
 * 내역에서 재수집 없이 저장 당시 결과를 그대로 다시 보여주기 위함.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_analyses', function (Blueprint $table) {
            $table->longText('report_html')->nullable()->after('snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('product_analyses', function (Blueprint $table) {
            $table->dropColumn('report_html');
        });
    }
};
