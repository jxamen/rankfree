<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 키워드 대량 분석 — 요청(batch) + 키워드별 행(item).
 * 대량이라 브라우저 폴링으로 청크 단위(AJAX) 수집한다. item.data 에 컬럼 데이터(JSON) 저장.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('status', 20)->default('pending'); // pending|processing|done|failed
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->boolean('include_serp')->default(true);   // 섹션배치 포함 여부
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bulk_keyword_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulk_keyword_id')->constrained()->cascadeOnDelete();
            $table->string('keyword');
            $table->string('status', 20)->default('pending'); // pending|done|failed
            $table->string('fail_reason')->nullable();
            $table->json('data')->nullable();                 // 수집된 컬럼 데이터
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['bulk_keyword_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_keyword_items');
        Schema::dropIfExists('bulk_keywords');
    }
};
