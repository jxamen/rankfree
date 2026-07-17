<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_seller_captchas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('store_id', 100)->nullable()->index();
            $table->string('channel_uid', 120)->index();
            $table->string('channel_id', 120)->nullable()->index();
            $table->string('captcha_key', 120)->index();
            $table->string('seller_info_type', 40)->default('profile');
            $table->string('question', 500)->nullable();
            $table->string('image_disk', 30)->default('local');
            $table->string('image_path', 500);
            $table->string('image_mime', 80)->nullable();
            $table->unsignedInteger('image_bytes')->default(0);
            $table->text('seller_info_url')->nullable();
            $table->text('prev_url')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->unique(['channel_uid', 'captcha_key'], 'ssc_channel_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_seller_captchas');
    }
};
