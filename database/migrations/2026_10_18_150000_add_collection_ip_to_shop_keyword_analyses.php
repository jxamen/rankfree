<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_keyword_analyses', function (Blueprint $t) {
            $t->string('collection_ip', 45)->nullable();
            $t->string('collection_source', 30)->nullable();
            $t->timestamp('collection_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_keyword_analyses', fn (Blueprint $t) => $t->dropColumn(['collection_ip', 'collection_source', 'collection_seen_at']));
    }
};
