<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_keyword_short_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_id')->constrained('shop_keyword_analyses')->cascadeOnDelete();
            $table->string('token', 32)->unique();
            $table->string('domain', 253)->nullable();
            $table->unsignedSmallInteger('group_no');
            $table->unsignedSmallInteger('group_count');
            $table->json('keywords');
            $table->json('reference_keywords')->nullable();
            $table->unsignedBigInteger('cursor')->default(0);
            $table->unsignedBigInteger('hit_count')->default(0);
            $table->timestamp('last_served_at')->nullable();
            $table->timestamps();

            $table->index(['analysis_id', 'group_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_keyword_short_links');
    }
};
