<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 소유자가 콘솔에서 키를 다시 복사할 수 있도록 원문을 암호화(Laravel encrypted)해 보관. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->text('key_encrypted')->nullable()->after('key_hash');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn('key_encrypted');
        });
    }
};
