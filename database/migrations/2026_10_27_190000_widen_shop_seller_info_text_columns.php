<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The business info API stores what the collector sends as received, so the short columns grow.
        // biz_reg_no keeps its index, so it only grows to 255.
        Schema::table('shop_seller_infos', function (Blueprint $table) {
            foreach (['biz_name', 'representative', 'customer_phone', 'mail_order_no', 'email'] as $column) {
                $table->string($column, 1000)->nullable()->change();
            }
            $table->string('biz_reg_no', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('shop_seller_infos', function (Blueprint $table) {
            $table->string('biz_name', 200)->nullable()->change();
            $table->string('representative', 120)->nullable()->change();
            $table->string('customer_phone', 60)->nullable()->change();
            $table->string('biz_reg_no', 40)->nullable()->change();
            $table->string('mail_order_no', 80)->nullable()->change();
            $table->string('email', 190)->nullable()->change();
        });
    }
};
