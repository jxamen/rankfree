<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * 쇼핑 순위체크 작업 큐(2026-08-03) — 네이버가 openapi shop.json 을 종료해(공지 32564)
         * 서버가 직접 순위를 못 구한다. 서버 크롤링도 막힌다: 쇼핑검색은 nCaptcha 토큰 없으면 418,
         * 구 search/all 은 로그인·캡차. 유일하게 되는 경로가 **확장이 검색 페이지 same-origin 으로
         * 부르는 시장분석 수집기**라, 확장이 켜진 PC 들을 워커 풀로 쓴다.
         *
         * 여러 대가 동시에 켜져 있어도 한 작업을 한 대만 가져가게 claim 을 원자적으로 한다.
         */
        Schema::create('shop_rank_jobs', function (Blueprint $table) {
            $table->id();

            $table->string('keyword', 100);
            $table->string('target_type', 10)->default('product');   // product | mall
            $table->string('product_id', 40)->nullable();
            $table->string('id_kind', 10)->default('channel');       // channel(스마트스토어) | nvmid(가격비교)
            $table->string('mall_name', 150)->nullable();
            $table->unsignedSmallInteger('pages')->default(1);       // 수집 페이지(1페이지 = 80위)

            // pending → claimed → done|failed. 재시도는 pending 으로 되돌린다.
            $table->string('status', 12)->default('pending');
            $table->string('claimed_by', 64)->nullable();            // 워커 식별자(확장 설치 단위)
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('lease_until')->nullable();            // 리스 만료 → 다른 워커가 회수
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();           // 백오프 — 이 시각 전에는 안 준다

            // 결과
            $table->integer('rank')->nullable();                     // 0=미노출, >0=오가닉 순위
            $table->boolean('found')->default(false);
            $table->boolean('ad_exposed')->default(false);
            $table->unsignedInteger('list_total')->default(0);
            $table->unsignedInteger('scanned')->default(0);          // 실제로 훑은 상품 수
            $table->string('title', 300)->nullable();
            $table->unsignedInteger('price')->nullable();
            $table->string('link', 500)->nullable();
            $table->string('image', 500)->nullable();
            $table->string('error', 60)->nullable();                 // captcha | empty | ...

            // 요청 출처 — 결과를 어디로 돌려주나
            $table->string('source', 12)->default('slot');           // slot | guest | admin
            $table->unsignedBigInteger('slot_id')->nullable();
            $table->string('request_token', 64)->nullable();         // 게스트 폴링용
            $table->unsignedBigInteger('user_id')->nullable();

            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // 큐 선별: 줄 수 있는 작업 찾기
            $table->index(['status', 'available_at'], 'srj_pick');
            $table->index(['status', 'lease_until'], 'srj_lease');   // 리스 만료 회수
            $table->index('request_token');
            $table->index(['slot_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_rank_jobs');
    }
};
