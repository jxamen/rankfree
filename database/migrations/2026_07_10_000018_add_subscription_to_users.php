<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 유료 구독 만료일 — 등급(요금제, grade_id)과 함께 구독 상태를 표현. null=무기한(무료 등급 등). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('subscription_expires_at')->nullable()->after('grade_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('subscription_expires_at');
        });
    }
};
