<?php

namespace Tests\Feature;

use App\Models\ExtToken;
use App\Models\KeywordCandidate;
use App\Models\KeywordCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 쇼핑 수집 대기열(mode=category) — 1개마다 다른 1차 분류 라운드 로빈(2026-07-22 확정). */
class ShopQueueRoundRobinTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_interleaves_one_keyword_per_root_category(): void
    {
        $u = User::create(['name' => 'a', 'email' => 'rr@rf.kr', 'password' => 'x1234567', 'role' => 'super']);
        [, $plain] = ExtToken::issue($u);

        $fashion = KeywordCategory::create(['type' => 'shopping', 'name' => '패션의류', 'slug' => 'rr-패션의류', 'is_active' => true, 'sort' => 0]);
        $beauty = KeywordCategory::create(['type' => 'shopping', 'name' => '화장품/미용', 'slug' => 'rr-화장품', 'is_active' => true, 'sort' => 1]);
        $child = KeywordCategory::create(['type' => 'shopping', 'name' => '원피스', 'slug' => 'rr-원피스', 'parent_id' => $fashion->id, 'is_active' => true]);

        // 패션(하위 포함) 3개 · 미용 2개 — 검색량 순
        KeywordCandidate::create(['category_id' => $child->id, 'keyword' => '패션A', 'monthly_total' => 900, 'status' => 'pending']);
        KeywordCandidate::create(['category_id' => $fashion->id, 'keyword' => '패션B', 'monthly_total' => 800, 'status' => 'pending']);
        KeywordCandidate::create(['category_id' => $fashion->id, 'keyword' => '패션C', 'monthly_total' => 700, 'status' => 'pending']);
        KeywordCandidate::create(['category_id' => $beauty->id, 'keyword' => '미용A', 'monthly_total' => 600, 'status' => 'pending']);
        KeywordCandidate::create(['category_id' => $beauty->id, 'keyword' => '미용B', 'monthly_total' => 500, 'status' => 'pending']);

        $d = $this->getJson('/api/ext/keyword-shop-serp/queue?mode=category&limit=5', ['Authorization' => 'Bearer '.$plain])
            ->assertOk()->json('data');

        // 1개마다 분류가 번갈아 나온다(패션→미용→패션→미용→패션), 분류 안에서는 검색량 순
        $this->assertSame(['패션A', '미용A', '패션B', '미용B', '패션C'], $d['keywords']);
        $this->assertSame(2, $d['category_total']);
        $this->assertSame('미수집 우선', $d['phase']);

        // 다음 배치는 커서가 돌아 미용부터 + 리스(방금 내준 키워드) 제외 → 남은 게 없다
        $d2 = $this->getJson('/api/ext/keyword-shop-serp/queue?mode=category&limit=5', ['Authorization' => 'Bearer '.$plain])
            ->assertOk()->json('data');
        $this->assertSame([], $d2['keywords']);
    }

    /**
     * 조회수 게이트(2026-07-23) — 수집 전에 검색량부터 확인해 월 조회수 10 이하는
     * 후보 리스트에서 삭제하고 수집하지 않는다.
     */
    public function test_volume_gate_deletes_low_volume_and_serves_rest(): void
    {
        $u = User::create(['name' => 'a', 'email' => 'vg@rf.kr', 'password' => 'x1234567', 'role' => 'super']);
        [, $plain] = ExtToken::issue($u);
        $cat = KeywordCategory::create(['type' => 'shopping', 'name' => '식품', 'slug' => 'vg-식품', 'is_active' => true, 'sort' => 0]);

        KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '이미저조', 'monthly_total' => 5, 'status' => 'pending']);
        KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '미상저조', 'status' => 'pending']);
        KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '미상무응답', 'status' => 'pending']);
        KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '미상인기', 'status' => 'pending']);

        $this->mock(\App\Domain\Keyword\NaverKeywordService::class, function ($m) {
            $m->shouldReceive('volumes')->andReturn([
                '미상저조' => ['monthly_total' => 3, 'monthly_pc' => 1, 'monthly_mobile' => 2, 'comp_idx' => '낮음'],
                '미상인기' => ['monthly_total' => 4200, 'monthly_pc' => 200, 'monthly_mobile' => 4000, 'comp_idx' => '높음'],
                // '미상무응답' 은 keywordstool 응답에서 생략됨 = 무데이터
            ]);
        });

        $d = $this->getJson('/api/ext/keyword-shop-serp/queue?mode=category&limit=5', ['Authorization' => 'Bearer '.$plain])
            ->assertOk()->json('data');

        // 조회수 있는 인기 키워드만 수집 대상으로 나간다(알려진 5 는 픽 자체가 안 되고, 3·무응답은 삭제)
        $this->assertSame(['미상인기'], $d['keywords']);
        $this->assertSame(0, KeywordCandidate::whereIn('keyword', ['미상저조', '미상무응답'])->count());
        // 조회된 검색량은 후보에 저장된다
        $this->assertSame(4200, (int) KeywordCandidate::where('keyword', '미상인기')->value('monthly_total'));
        $this->assertNotNull(KeywordCandidate::where('keyword', '미상인기')->value('volume_checked_at'));
    }

    public function test_volume_gate_passes_through_on_api_failure(): void
    {
        $u = User::create(['name' => 'a', 'email' => 'vg2@rf.kr', 'password' => 'x1234567', 'role' => 'super']);
        [, $plain] = ExtToken::issue($u);
        $cat = KeywordCategory::create(['type' => 'shopping', 'name' => '가전', 'slug' => 'vg-가전', 'is_active' => true, 'sort' => 0]);
        KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '조회실패A', 'status' => 'pending']);
        KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '조회실패B', 'status' => 'pending']);

        // 청크 통실패(빈 응답) — API 장애일 수 있으니 삭제하지 않고 그대로 수집한다
        $this->mock(\App\Domain\Keyword\NaverKeywordService::class, function ($m) {
            $m->shouldReceive('volumes')->andReturn([]);
        });

        $d = $this->getJson('/api/ext/keyword-shop-serp/queue?mode=category&limit=5', ['Authorization' => 'Bearer '.$plain])
            ->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(['조회실패A', '조회실패B'], $d['keywords']);
        $this->assertSame(2, KeywordCandidate::count());
    }
}
