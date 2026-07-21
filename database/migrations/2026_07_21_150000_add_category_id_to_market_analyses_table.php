<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_analyses', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('user_id')->constrained('keyword_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('market_analyses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
    }
};
