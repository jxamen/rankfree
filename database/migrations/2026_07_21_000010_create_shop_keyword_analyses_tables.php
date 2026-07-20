<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 쇼핑 노출 키워드 분석(25) — 핵심 키워드 + 상품 URL → 키워드 추출 → 조합 → 쇼핑 순위체크.
 * 어떤 조합이 내 상품을 쇼핑 상위 N위(기본 5)에 노출시키는지 판정해 SEO 개선 근거로 쓴다.
 *  - shop_keyword_analyses: 분석 1회(입력·요약)
 *  - shop_keyword_analysis_items: 추출된 키워드(kind=token)와 조합 후보(kind=combo, 순위체크 대상)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_keyword_analyses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('core_keyword', 120);
            $t->string('product_url', 500)->nullable();
            $t->string('product_id', 40)->nullable();
            $t->string('mall_name', 150)->nullable();
            $t->string('product_title', 200)->nullable();
            $t->unsignedTinyInteger('threshold')->default(5);   // 이 순위 이내면 "노출"
            $t->unsignedSmallInteger('token_count')->default(0);
            $t->unsignedSmallInteger('combo_count')->default(0);
            $t->unsignedSmallInteger('checked_count')->default(0);
            $t->unsignedSmallInteger('exposed_count')->default(0);
            $t->string('status', 20)->default('done');          // done | blocked | error
            $t->timestamps();
            $t->index(['user_id', 'created_at']);
        });

        Schema::create('shop_keyword_analysis_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('analysis_id')->constrained('shop_keyword_analyses')->cascadeOnDelete();
            $t->string('kind', 8);          // token | combo
            $t->string('source', 20);       // autocomplete|related|together|brand|keyword_rec|attribute|product|manual|combo
            $t->string('keyword', 120);
            $t->integer('rank')->nullable();            // combo 만: null=미확인 · 0=순위 밖 · -1=차단 · >0 순위
            $t->unsignedBigInteger('monthly_total')->nullable();
            $t->timestamp('checked_at')->nullable();
            $t->timestamps();
            $t->unique(['analysis_id', 'kind', 'keyword'], 'ska_uni');
            $t->index(['analysis_id', 'rank'], 'ska_rank');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_keyword_analysis_items');
        Schema::dropIfExists('shop_keyword_analyses');
    }
};
