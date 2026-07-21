<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shop_keyword_short_links') || Schema::hasColumn('shop_keyword_short_links', 'domain')) {
            return;
        }

        Schema::table('shop_keyword_short_links', function (Blueprint $table) {
            $table->string('domain', 253)->nullable()->after('token');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('shop_keyword_short_links') || ! Schema::hasColumn('shop_keyword_short_links', 'domain')) {
            return;
        }

        Schema::table('shop_keyword_short_links', function (Blueprint $table) {
            $table->dropColumn('domain');
        });
    }
};
