<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 키워드별 플레이스 노출 업체 스냅샷.
 * 순위·리뷰수는 자주 바뀌지 않아 매번 재수집할 필요가 없다 — 한 번 수집해 저장하고 '다시 수집' 때만 갱신한다.
 * (1키워드 = 1행, items 에 최대 300개 업체를 JSON 으로)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_place_serps', function (Blueprint $t) {
            $t->id();
            $t->string('keyword', 120);
            $t->string('cat', 20)->default('place');   // pcmap 업종 키(restaurant·hospital…)
            $t->unsignedInteger('total')->default(0);  // 네이버가 알려주는 전체 노출 수
            $t->unsignedSmallInteger('item_count')->default(0);
            $t->json('items');                          // serpItem 목록(순위·업체명·리뷰·place+·새로오픈·톡톡)
            $t->timestamp('collected_at')->nullable();
            $t->timestamps();
            $t->unique(['keyword', 'cat']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_place_serps');
    }
};
