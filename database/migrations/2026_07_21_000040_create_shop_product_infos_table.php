<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 확장이 상품 페이지(__PRELOADED_STATE__ / simpleProductForDetailPage.A)에서 수집한 상품정보.
 * 쇼핑 노출 키워드 분석(25)이 조합 재료(제목·브랜드·가격·SEO태그)로 사용한다.
 * 서버는 상품 페이지를 직접 못 읽으므로(429) 확장 수집분을 여기 저장·조회한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_product_infos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('channel_product_id', 40)->index();   // URL /products/{id}
            $t->string('title', 300)->nullable();
            $t->string('brand', 120)->nullable();            // naverShoppingSearchInfo.brandName / manufacturerName
            $t->string('mall_name', 150)->nullable();        // channel.channelName
            $t->unsignedInteger('price')->nullable();        // salePrice
            $t->json('seller_tags')->nullable();             // seoInfo.sellerTags[].text
            $t->string('category', 191)->nullable();
            $t->timestamp('collected_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'channel_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_product_infos');
    }
};
