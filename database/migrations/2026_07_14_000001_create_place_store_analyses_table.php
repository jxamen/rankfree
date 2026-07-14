<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 플레이스 매장 분석(정밀 N1/N2/N3 + D지표) 저장 — 확장 프로그램 매장분석 탭. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_store_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('place_id', 30);
            $table->string('name', 120);
            $table->string('keyword', 100);
            $table->string('cat', 30)->nullable();          // restaurant/place 등 pcmap 카테고리
            $table->unsignedInteger('rank')->nullable();    // 키워드 내 순위(300=상위권 밖)
            $table->decimal('n1', 5, 1)->nullable();
            $table->decimal('n2', 5, 1)->nullable();
            $table->decimal('n3', 5, 1)->nullable();
            $table->unsignedInteger('visitor_cnt')->nullable();
            $table->unsignedInteger('blog_cnt')->nullable();
            $table->unsignedInteger('save_cnt')->nullable();
            $table->json('detail'); // d1~d10·tier 등 정밀 지표 스냅샷
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            // 같은 매장×키워드 재분석은 갱신(updateOrCreate) — 내역 중복 방지
            $table->unique(['user_id', 'place_id', 'keyword']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_store_analyses');
    }
};
