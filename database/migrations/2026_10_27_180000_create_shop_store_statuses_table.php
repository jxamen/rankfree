<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per store: the collector reported "operation suspended" (the seller's profile says so) or reopened it.
        // The suspended time and the received message are kept as the record of the last suspension, also after release.
        Schema::create('shop_store_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('store_id', 100)->unique();
            $table->string('status', 16)->index();
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspended_message')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('observed_at');
            $table->text('source_url')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_store_statuses');
    }
};
