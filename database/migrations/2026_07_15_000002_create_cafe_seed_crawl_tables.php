<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 카페 글감 수집(크롤) 원본 + 시드 사용 이력.
 *  - cafe_crawl_articles/comments: naver-cafe-crawler.cjs 수집 원본(제목·본문·작성일·댓글).
 *    unique(cafe_id, article_id)로 재수집 시 중복 방지, seed_id/seeded_at 로 글밥(community_seeds) 전환 추적.
 *  - community_seed_usages: 글밥이 실제 재작성·게시에 사용된 기록(언제·어떤 페르소나·어떤 결과물·어떤 AI).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cafe_crawl_articles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cafe_id');
            $table->unsignedBigInteger('article_id');          // 네이버 글 번호
            $table->string('title', 300);
            $table->text('body')->nullable();                  // 본문 텍스트(비멤버 수집 등은 null)
            $table->string('writer', 80)->nullable();
            $table->dateTime('wrote_at')->nullable();          // 원글 작성일(UTC)
            $table->unsignedInteger('read_count')->default(0);
            $table->unsignedInteger('comment_count')->default(0);
            $table->string('url', 255)->nullable();
            $table->foreignId('seed_id')->nullable()->constrained('community_seeds')->nullOnDelete();
            $table->timestamp('seeded_at')->nullable();        // 글밥 전환 시각
            $table->timestamp('crawled_at')->nullable();       // 마지막 수집 시각
            $table->timestamps();
            $table->unique(['cafe_id', 'article_id']);
        });

        Schema::create('cafe_crawl_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crawl_article_id')->constrained('cafe_crawl_articles')->cascadeOnDelete();
            $table->unsignedBigInteger('comment_id');          // 네이버 댓글 id
            $table->unsignedBigInteger('parent_comment_id')->nullable(); // 대댓글이면 원댓글 id
            $table->string('writer', 80)->nullable();
            $table->text('content');
            $table->dateTime('wrote_at')->nullable();          // 댓글 작성일(UTC)
            $table->boolean('is_deleted')->default(false);
            $table->foreignId('seed_id')->nullable()->constrained('community_seeds')->nullOnDelete();
            $table->timestamp('seeded_at')->nullable();
            $table->timestamps();
            $table->unique(['crawl_article_id', 'comment_id']);
        });

        Schema::create('community_seed_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seed_id')->constrained('community_seeds')->cascadeOnDelete();
            $table->foreignId('persona_id')->nullable()->constrained('personas')->nullOnDelete();
            $table->string('used_for', 10);                    // post | comment
            $table->foreignId('post_id')->nullable()->constrained('community_posts')->nullOnDelete();     // 생성된 글
            $table->foreignId('comment_id')->nullable()->constrained('community_comments')->nullOnDelete(); // 생성된 댓글
            $table->string('provider', 20)->nullable();        // gemini | anthropic | fallback
            $table->timestamps();                              // created_at = 사용 시각
        });

        Schema::table('community_seeds', function (Blueprint $table) {
            $table->timestamp('last_used_at')->nullable()->after('used_count');
        });
    }

    public function down(): void
    {
        Schema::table('community_seeds', function (Blueprint $table) {
            $table->dropColumn('last_used_at');
        });
        Schema::dropIfExists('community_seed_usages');
        Schema::dropIfExists('cafe_crawl_comments');
        Schema::dropIfExists('cafe_crawl_articles');
    }
};
