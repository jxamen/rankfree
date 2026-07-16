<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO 공유 슬러그 — 분석 공유 URL 을 랜덤 토큰에서 한글/영문 슬러그로 전환.
 *   예) /keyword/여름브라 · /place/강남맛집 · /shopping/여름원피스
 * 각 테이블 내 유일. 기존 share_token 은 하위호환(구 링크 301 리다이렉트)용으로 유지.
 */
return new class extends Migration
{
    private array $tables = [
        'keyword_searches',
        'market_analyses',
        'product_analyses',
        'seller_power_analyses',
        'place_store_analyses',
        'place_rank_slots',
        'shop_rank_slots',
    ];

    public function up(): void
    {
        foreach ($this->tables as $t) {
            if (! Schema::hasColumn($t, 'slug')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->string('slug', 180)->nullable()->unique()->after('id');
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $t) {
            if (Schema::hasColumn($t, 'slug')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->dropColumn('slug');
                });
            }
        }
    }
};
