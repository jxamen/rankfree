<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_product_summaries', function (Blueprint $t) {
            // 0 = all months; distinct keyword count must not be summed across months.
            $t->unsignedInteger('collected_month');
            $t->string('product_key', 64);
            $t->string('title', 300);
            $t->unsignedInteger('price')->nullable();
            $t->string('mall_name', 120)->nullable();
            $t->boolean('is_ad');
            $t->boolean('has_talk');
            $t->unsignedInteger('kw_cnt');
            $t->unsignedSmallInteger('best_rnk')->nullable();
            $t->timestamp('last_at')->nullable();
            $t->primary(['collected_month', 'product_key']);
            $t->index(['product_key'], 'sps_product');
            $t->index(['collected_month', 'last_at', 'product_key'], 'sps_recent');
            $t->index(['collected_month', 'kw_cnt', 'last_at', 'product_key'], 'sps_kw');
            $t->index(['collected_month', 'price', 'product_key'], 'sps_price');
            $t->index(['collected_month', 'title', 'product_key'], 'sps_title');
            $t->index(['collected_month', 'mall_name', 'last_at', 'product_key'], 'sps_mall');
            $t->index(['collected_month', 'is_ad', 'last_at', 'product_key'], 'sps_ad');
            $t->index(['collected_month', 'has_talk', 'last_at', 'product_key'], 'sps_talk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_product_summaries');
    }
};
