<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_keyword_analyses', fn (Blueprint $table) => $table->json('product_collection')->nullable());
    }

    public function down(): void
    {
        Schema::table('shop_keyword_analyses', fn (Blueprint $table) => $table->dropColumn('product_collection'));
    }
};
