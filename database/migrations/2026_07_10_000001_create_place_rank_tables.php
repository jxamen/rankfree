<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A1 플레이스 순위체크 — nCaptcha 토큰 저장 + 조회 로그.
 * (추적 슬롯/일자별 이력 테이블은 콘솔 단계에서 별도 마이그레이션으로 추가)
 */
return new class extends Migration
{
    public function up(): void
    {
        // nCaptcha 토큰(세션 범용, 단일 레코드). 로컬 발급 도구가 갱신 → 서버 조회가 사용.
        Schema::create('place_rank_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 512)->default('');
            $table->timestamp('updated_at')->nullable();
        });

        // 순위 조회 로그(무료 카운트·분석·재현용). 익명(1회성) + 회원 공용.
        Schema::create('place_rank_lookups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('keyword', 120)->index();
            $table->string('place_id', 30)->index();
            $table->string('category', 20)->default('place');
            $table->integer('rank')->default(0);          // 0/300=순위밖, -429=차단
            $table->integer('list_total')->default(0);    // 리스트 총 노출 수
            $table->unsignedInteger('review_count')->nullable();
            $table->unsignedInteger('blog_review_count')->nullable();
            $table->unsignedInteger('save_count')->nullable();
            $table->decimal('review_score', 3, 2)->nullable();
            $table->string('place_name', 150)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_rank_lookups');
        Schema::dropIfExists('place_rank_tokens');
    }
};
