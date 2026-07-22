<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 쇼핑 노출 키워드 분석 — 브랜드/업체명 분리.
 * 기존 mall_name 컬럼이 "콤보용 브랜드"(제조사·브랜드)와 "실제 상점명" 두 역할을 겸하며
 * 브랜드가 우선 저장돼, 화면 업체명에 종근당·비타500 같은 브랜드가 표기되던 결함 수정(2026-07-22 실사고).
 * 백필: 확장 수집분(shop_product_infos)이 있으면 그 값으로 정정, 없으면 기존 값을 브랜드로 이동.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_keyword_analyses', function (Blueprint $t) {
            $t->string('brand', 120)->nullable()->after('mall_name');
        });

        foreach (DB::table('shop_keyword_analyses')->whereNotNull('product_id')->get(['id', 'user_id', 'product_id', 'mall_name']) as $a) {
            $pi = DB::table('shop_product_infos')->where('channel_product_id', $a->product_id)
                ->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$a->user_id])
                ->orderByDesc('collected_at')->first(['brand', 'mall_name']);
            if ($pi) {
                DB::table('shop_keyword_analyses')->where('id', $a->id)
                    ->update(['brand' => $pi->brand, 'mall_name' => $pi->mall_name]);
            } elseif ($a->mall_name !== null) {
                // 수집분 없음 — 기존 값은 브랜드였을 가능성이 높으므로 브랜드로 이동(업체명은 비움)
                DB::table('shop_keyword_analyses')->where('id', $a->id)
                    ->update(['brand' => $a->mall_name, 'mall_name' => null]);
            }
        }
        // product_id 없는(업체명 입력) 분석은 mall_name 이 실제 업체명 — 그대로 둔다.
    }

    public function down(): void
    {
        Schema::table('shop_keyword_analyses', function (Blueprint $t) {
            $t->dropColumn('brand');
        });
    }
};
