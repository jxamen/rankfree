<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_keyword_analysis_items', function (Blueprint $table) {
            $table->string('result_source', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_keyword_analysis_items', fn (Blueprint $table) => $table->dropColumn('result_source'));
    }
};
