<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_hub_runs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->default('both');
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('total_jobs')->default(0);
            $table->unsignedInteger('finished_jobs')->default(0);
            $table->unsignedInteger('failed_jobs')->default(0);
            $table->unsignedInteger('seeds')->default(0);
            $table->unsignedInteger('created_candidates')->default(0);
            $table->unsignedInteger('updated_candidates')->default(0);
            $table->unsignedInteger('filtered_candidates')->default(0);
            $table->json('options')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('keyword_hub_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('keyword_hub_runs')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('target_type', 30);
            $table->string('target_id', 80)->nullable();
            $table->string('label', 160);
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('seeds')->default(0);
            $table->unsignedInteger('created_candidates')->default(0);
            $table->unsignedInteger('updated_candidates')->default(0);
            $table->unsignedInteger('filtered_candidates')->default(0);
            $table->string('note', 500)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'status']);
            $table->index(['type', 'status']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_hub_run_items');
        Schema::dropIfExists('keyword_hub_runs');
    }
};
