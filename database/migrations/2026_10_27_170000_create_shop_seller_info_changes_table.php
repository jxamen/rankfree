<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_seller_info_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_seller_info_id')->constrained('shop_seller_infos')->cascadeOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('store_id', 100)->index();
            $table->json('changes');
            $table->text('source_url');
            $table->timestamp('observed_at');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_seller_info_changes');
    }
};
