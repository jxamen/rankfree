<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 요금제(등급)별 기능 횟수 제한 + 회원별 월간 사용량 집계. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_grades', function (Blueprint $table) {
            // { "keyword_analysis": 100, "market_analysis": 50, ... }  (-1=무제한, 0=미제공)
            $table->json('feature_limits')->nullable()->after('rank_slot_limit');
        });

        Schema::create('feature_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 40);
            $table->string('period', 7); // YYYY-MM (월간 집계)
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'feature', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_usages');
        Schema::table('member_grades', function (Blueprint $table) {
            $table->dropColumn('feature_limits');
        });
    }
};
