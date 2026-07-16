<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** GA4 방문 통계 적재 — 일별 × 차원(date 합계 · channel · source · page). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ga_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('dimension', 10);           // date(일 합계) | channel | source | page
            $table->string('value', 500)->default('');
            $table->unsignedInteger('users')->default(0);
            $table->unsignedInteger('new_users')->default(0);
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('pageviews')->default(0);
            $table->timestamps();
            $table->unique(['date', 'dimension', 'value']);
            $table->index(['dimension', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ga_stats');
    }
};
