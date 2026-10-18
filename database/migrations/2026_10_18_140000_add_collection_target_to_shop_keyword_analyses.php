<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_keyword_analyses', function (Blueprint $table) {
            $table->date('order_start_date')->nullable();
            $table->date('order_end_date')->nullable();
            $table->unsignedInteger('order_days')->nullable();
            $table->unsignedInteger('keyword_target')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_keyword_analyses', fn (Blueprint $table) => $table->dropColumn([
            'order_start_date', 'order_end_date', 'order_days', 'keyword_target',
        ]));
    }
};
