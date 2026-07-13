<?php

namespace Tests\Feature;

use App\Models\BlogIndexAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 블로그 수집/분석 진입 화면 — 최근 내역 리스트 표시 + 내역 없을 때만 빈 카드. */
class BlogHistoryListTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['name' => 'u', 'email' => 'u@rf.kr', 'password' => 'x1234567']);
    }

    public function test_history_list_shown_on_both_entry_pages(): void
    {
        $user = $this->user();
        // 단건(blog) 내역 — blog-index 진입에서 노출
        BlogIndexAnalysis::create([
            'user_id' => $user->id, 'type' => 'blog', 'query' => 'today789',
            'title' => '맛집 블로그', 'score' => 80, 'grade' => 'A', 'blogger_count' => 0, 'snapshot' => [],
        ]);
        // 키워드 내역 — blog-collect 진입에서 노출
        BlogIndexAnalysis::create([
            'user_id' => $user->id, 'type' => 'keyword', 'query' => '강남 맛집',
            'title' => '강남 맛집', 'score' => 70, 'grade' => null, 'blogger_count' => 15, 'snapshot' => [],
        ]);

        // blog-index (단건 분석 진입) → 블로그 타입 내역
        $this->actingAs($user)->get('/console/blog-index')
            ->assertOk()->assertSee('최근 분석 내역')->assertSee('맛집 블로그')
            ->assertDontSee('블로그를 분석해 보세요');

        // blog-collect (수집 진입) → 키워드 타입 내역
        $this->actingAs($user)->get('/console/blog-collect')
            ->assertOk()->assertSee('최근 분석 내역')->assertSee('강남 맛집');
    }

    public function test_empty_card_only_when_no_history(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get('/console/blog-index')
            ->assertOk()->assertSee('블로그를 분석해 보세요')->assertDontSee('최근 분석 내역');
    }
}
