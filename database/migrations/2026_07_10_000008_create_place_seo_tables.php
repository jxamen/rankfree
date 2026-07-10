<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 경쟁분석(SEO 점수) 일별 스냅샷 — 순위추적 슬롯을 트랙으로 사용. crm serp/score/place_daily 이식. */
return new class extends Migration
{
    public function up(): void
    {
        // T1 일별 경쟁셋 순위 스냅샷
        Schema::create('place_seo_serp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slot_id')->constrained('place_rank_slots')->cascadeOnDelete();
            $table->date('ymd');
            $table->smallInteger('rnk')->default(0);
            $table->string('place_id', 30)->default('');
            $table->string('name', 200)->default('');
            $table->integer('visitor_cnt')->nullable();
            $table->integer('blog_cnt')->nullable();
            $table->integer('booking_cnt')->nullable();
            $table->integer('save_cnt')->nullable();
            $table->decimal('review_score', 3, 2)->nullable();
            $table->text('tags')->nullable();          // JSON
            $table->string('address', 255)->default('');
            $table->boolean('is_mine')->default(false);
            $table->integer('list_total')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->unique(['slot_id', 'ymd', 'rnk', 'is_mine']);
            $table->index(['place_id', 'ymd']);
        });

        // 일별 점수(시계열 핵심)
        Schema::create('place_seo_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slot_id')->constrained('place_rank_slots')->cascadeOnDelete();
            $table->string('place_id', 30);
            $table->date('ymd');
            $table->smallInteger('rnk')->default(300);
            foreach (['d1', 'd2', 'd3', 'd4', 'd5', 'd7', 'd8', 'd9', 'd10', 'n1', 'n2', 'n3'] as $col) {
                $table->decimal($col, 6, 3)->nullable();
            }
            $table->smallInteger('avail_mask')->default(0);
            $table->tinyInteger('tier')->default(1);   // 2=상세수집, 1=리스트만
            $table->boolean('is_mine')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->unique(['slot_id', 'place_id', 'ymd']);
            $table->index('ymd');
        });

        // 일별 플레이스 상세신호(place 단위 공유)
        Schema::create('place_seo_daily', function (Blueprint $table) {
            $table->id();
            $table->string('place_id', 30);
            $table->date('ymd');
            $table->string('name', 200)->default('');
            $table->string('category', 60)->default('');
            $table->integer('visitor_cnt')->nullable();
            $table->integer('blog_cnt')->nullable();
            $table->integer('booking_cnt')->nullable();
            $table->integer('save_cnt')->nullable();
            $table->decimal('review_score', 3, 2)->nullable();
            $table->smallInteger('menu_cnt')->nullable();
            $table->smallInteger('photo_cnt')->nullable();
            $table->tinyInteger('conv_cnt')->nullable();
            $table->tinyInteger('pay_cnt')->nullable();
            $table->tinyInteger('keyword_cnt')->nullable();
            $table->tinyInteger('category_cnt')->nullable();
            $table->smallInteger('stylist_cnt')->nullable();
            $table->tinyInteger('has_road')->nullable();
            $table->tinyInteger('has_talktalk')->nullable();
            $table->tinyInteger('has_chatbot')->nullable();
            $table->tinyInteger('has_booking')->nullable();
            $table->tinyInteger('hide_hours')->nullable();
            $table->tinyInteger('hide_price')->nullable();
            $table->tinyInteger('missing_cnt')->nullable();
            $table->string('missing_labels', 120)->default('');
            $table->tinyInteger('place_plus')->nullable();
            $table->text('tags')->nullable();          // JSON
            $table->text('review_kw')->nullable();      // JSON
            $table->timestamp('created_at')->nullable();
            $table->unique(['place_id', 'ymd']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_seo_daily');
        Schema::dropIfExists('place_seo_scores');
        Schema::dropIfExists('place_seo_serp');
    }
};
