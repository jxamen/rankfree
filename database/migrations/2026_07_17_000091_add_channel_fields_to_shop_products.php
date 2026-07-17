<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_products', function (Blueprint $table) {
            $table->string('store_id', 100)->nullable()->index()->after('mall_name');
            $table->string('channel_uid', 120)->nullable()->index()->after('store_id');
            $table->string('channel_id', 120)->nullable()->index()->after('channel_uid');
            $table->unsignedBigInteger('channel_no')->nullable()->after('channel_id');
        });
    }

    public function down(): void
    {
        Schema::table('shop_products', function (Blueprint $table) {
            $table->dropIndex(['store_id']);
            $table->dropIndex(['channel_uid']);
            $table->dropIndex(['channel_id']);
            $table->dropColumn(['store_id', 'channel_uid', 'channel_id', 'channel_no']);
        });
    }
};
