<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 블로그 리뷰어 품질 프로필 캐시 — crm crm_blog_profile 이식. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_profiles', function (Blueprint $table) {
            $table->string('blog_id', 40)->primary();
            $table->string('blog_name', 120)->default('');
            $table->string('nick_name', 60)->default('');
            $table->string('directory', 60)->default('');
            $table->boolean('power_blog')->default(false);
            $table->integer('subscriber_cnt')->nullable();
            $table->bigInteger('total_visitor')->nullable();
            $table->integer('day_visitor_avg')->nullable();
            $table->string('visitor5_json', 400)->nullable();
            $table->date('since_date')->nullable();
            $table->integer('post_total')->nullable();
            $table->decimal('post_per_week', 6, 2)->nullable();
            $table->decimal('avg_comment', 6, 1)->nullable();
            $table->text('posts_json')->nullable();
            $table->text('kw_json')->nullable();
            $table->decimal('score', 6, 2)->nullable();
            $table->boolean('ok')->default(false);
            $table->timestamp('fetched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_profiles');
    }
};
