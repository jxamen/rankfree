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
}
