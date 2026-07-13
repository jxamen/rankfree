<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 쇼핑 순위추적 — 플레이스 순위추적(place_rank_slots/records) 미러.
 * 대상 = 상품(URL productId) 또는 업체(mallName). openapi shop.json 검색 순위를 일별 기록.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_rank_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('keyword', 120);
            $table->string('target_type', 10)->default('product');   // product | mall
            $table->string('product_id', 40)->nullable();
            $table->string('mall_name', 150)->nullable();
            $table->string('product_url', 255)->nullable();
            $table->string('product_title', 200)->nullable();
            $table->string('label', 100)->nullable();
            $table->string('share_token', 64)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->integer('last_rank')->nullable();
            $table->unsignedInteger('last_price')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('shop_rank_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slot_id')->constrained('shop_rank_slots')->cascadeOnDelete();
            $table->integer('rank')->default(0);
            $table->unsignedInteger('price')->nullable();
            $table->integer('list_total')->default(0);
            $table->date('checked_date');
            $table->timestamp('created_at')->nullable();
            $table->unique(['slot_id', 'checked_date']);   // 1 slot 1 record/day
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_rank_records');
        Schema::dropIfExists('shop_rank_slots');
    }
};
