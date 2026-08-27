<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 부스팅샵 전송값 저장(2026-08-27) — 확인 화면에서 다듬은 값(상품번호·상호명·유입 키워드·노출 순위 등)을
 * 주문에 남겨 다시 열었을 때 그대로 복원한다. 접수 전에 여러 번 나눠 준비할 수 있게 하는 용도.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_orders', function (Blueprint $table) {
            $table->json('boosting_draft')->nullable()->after('field_values');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_orders', function (Blueprint $table) {
            $table->dropColumn('boosting_draft');
        });
    }
};
