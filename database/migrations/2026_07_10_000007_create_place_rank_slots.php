<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 순위 추적 슬롯(키워드×플레이스) + 일자별 순위 기록. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_rank_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('keyword', 120);
            $table->string('place_id', 30)->nullable();
            $table->string('place_name', 150)->nullable();
            $table->string('place_url', 255)->nullable();
            $table->string('category', 20)->default('place');
            $table->string('label', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('last_rank')->nullable();            // 0/300=순위밖, -429=차단
            $table->unsignedInteger('last_review_count')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('place_rank_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slot_id')->constrained('place_rank_slots')->cascadeOnDelete();
            $table->integer('rank')->default(0);
            $table->unsignedInteger('review_count')->nullable();
            $table->unsignedInteger('save_count')->nullable();
            $table->decimal('review_score', 3, 2)->nullable();
            $table->integer('list_total')->default(0);
            $table->date('checked_date');
            $table->timestamp('created_at')->nullable();
            $table->unique(['slot_id', 'checked_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_rank_records');
        Schema::dropIfExists('place_rank_slots');
    }
};
