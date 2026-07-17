<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_seller_infos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('store_id', 100)->nullable()->index();
            $table->string('channel_uid', 120)->index();
            $table->string('channel_id', 120)->nullable()->index();
            $table->string('biz_name', 200)->nullable();
            $table->string('representative', 120)->nullable();
            $table->string('customer_phone', 60)->nullable();
            $table->string('biz_reg_no', 40)->nullable()->index();
            $table->string('mail_order_no', 80)->nullable();
            $table->string('email', 190)->nullable();
            $table->text('address')->nullable();
            $table->json('raw')->nullable();
            $table->text('seller_info_url')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            // One row per seller channel for upsert.
            $table->unique('channel_uid', 'ssi_channel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_seller_infos');
    }
};
