<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_product_list_options', function (Blueprint $t) {
            $t->string('name', 32)->primary();
            $t->json('value');
            $t->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_product_list_options');
    }
};
