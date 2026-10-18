<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->unsignedBigInteger('prepaid_balance')->default(0);
        });
        Schema::create('topups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->unsignedBigInteger('amount');
            $t->string('depositor', 100);
            $t->string('code', 32)->unique();
            $t->string('status')->default('pending');
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('wallet_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->bigInteger('amount');
            $t->unsignedBigInteger('balance_after');
            $t->string('reference')->unique();
            $t->string('description');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_entries');
        Schema::dropIfExists('topups');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('prepaid_balance'));
    }
};
