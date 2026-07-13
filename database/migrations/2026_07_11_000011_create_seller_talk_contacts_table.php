<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 셀러력 수집 중 확보한 판매자 톡톡/스토어 연락 식별자 모음(마케팅 리드).
 * 키워드·몰이름·순위·톡톡아이디·수집일 조합으로 저장. 조회는 슈퍼어드민만.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_talk_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('keyword', 120);              // 수집 기준 검색어
            $table->string('mall_name', 200)->nullable(); // 몰(스토어) 이름
            $table->unsignedSmallInteger('rank')->default(0); // 수집 시 노출 순위
            $table->string('talk_id', 150);              // 톡톡 아이디/스토어 핸들
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('collected_at')->nullable(); // 수집일시
            $table->timestamps();

            $table->unique(['keyword', 'talk_id'], 'stc_keyword_talk');
            $table->index('collected_at');
            $table->index('mall_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_talk_contacts');
    }
};
