<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 키워드별 쇼핑 노출 상품 스냅샷(상위 80개).
 * 네이버 쇼핑 검색 API 는 서버 요청을 418 로 막기 때문에 확장이 브라우저에서 수집해 여기에 저장한다.
 * 플레이스와 동일하게 한 번 수집하면 재사용하고 '다시 수집' 때만 갱신한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_shop_serps', function (Blueprint $t) {
            $t->id();
            $t->string('keyword', 120)->unique();
            $t->unsignedInteger('total')->default(0);      // 네이버 전체 노출 상품 수
            $t->unsignedSmallInteger('item_count')->default(0);
            $t->json('items');                              // 상위 80개(순위·상품명·가격·몰명·광고여부…)
            $t->json('related_tags')->nullable();           // 함께 많이 찾는 태그
            $t->timestamp('collected_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_shop_serps');
    }
};
