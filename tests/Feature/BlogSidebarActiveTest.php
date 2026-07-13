<?php

namespace Tests\Feature;

use App\Models\BlogIndexAnalysis;
use App\Models\Menu;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 블로그 지수분석 상세(console.blog.show)에서 분석 타입에 맞는 사이드바 메뉴가 활성되는지. */
class BlogSidebarActiveTest extends TestCase
{
    use RefreshDatabase;

    private function setup2(): User
    {
        Menu::create(['area' => 'console', 'name' => '블로그 수집', 'route' => 'console.blog', 'is_active' => true, 'is_group' => false]);
        Menu::create(['area' => 'console', 'name' => '블로그 지수 분석', 'route' => 'console.blog-single', 'is_active' => true, 'is_group' => false]);

        // 슈퍼어드민: 사이드바 전체 노출 + 게이트 통과
        return User::create(['name' => 'u', 'email' => 's'.uniqid().'@rf.kr', 'password' => 'x1234567', 'role' => 'super']);
    }

    /** class 순서·sb-link/sb-sublink 무관하게 특정 라벨 링크가 활성(.on)인지. */
    private function activeRe(string $label): string
    {
        return '/class="sb-(?:sub)?link[^"]*\bon\b[^"]*"[^>]*data-label="'.preg_quote($label, '/').'"/u';
    }

    public function test_blog_type_detail_activates_index_menu(): void
    {
        $user = $this->setup2();
        $a = BlogIndexAnalysis::create([
            'user_id' => $user->id, 'type' => 'blog', 'query' => 'someblog',
            'title' => '맛집 블로그', 'score' => 80, 'grade' => 'A', 'blogger_count' => 0,
            'snapshot' => [
                'blog_id' => 'testblog', 'score' => 80, 'grade' => 'A',
                'profile' => [
                    'blog_name' => '테스트 블로그', 'power_blog' => false, 'influencer' => false, 'directory' => '',
                    'subscriber_cnt' => 100, 'day_visitor_avg' => 50, 'total_visitor' => 1000,
                    'post_per_week' => 3, 'avg_comment' => 2, 'post_total' => 200,
                    'visitor5' => [], 'top_focus' => 60, 'last_post' => null,
                ],
                'quality' => ['analyzed' => 5, 'avg_photos' => 10, 'avg_length' => 1500, 'video_ratio' => 20, 'top_words' => []],
                'breakdown' => ['profile' => 50, 'content' => 50, 'focus' => 50, 'activity' => 50, 'comment' => 50, 'visitor' => 50, 'subscriber' => 50, 'age' => 50, 'photo' => 50, 'text' => 50],
                'posts' => [],
            ],
        ]);

        $html = $this->actingAs($user)->get('/console/blog-index/'.$a->id)->assertOk()->getContent();

        $this->assertMatchesRegularExpression($this->activeRe('블로그 지수 분석'), $html);   // 지수분석 활성
        $this->assertDoesNotMatchRegularExpression($this->activeRe('블로그 수집'), $html);    // 수집 비활성
    }

    public function test_keyword_type_detail_activates_collect_menu(): void
    {
        $user = $this->setup2();
        $a = BlogIndexAnalysis::create([
            'user_id' => $user->id, 'type' => 'keyword', 'query' => '강남 맛집',
            'title' => '강남 맛집', 'score' => 70, 'grade' => null, 'blogger_count' => 0,
            'snapshot' => ['keyword' => '강남 맛집', 'bloggers' => []],
        ]);

        $html = $this->actingAs($user)->get('/console/blog-index/'.$a->id)->assertOk()->getContent();

        $this->assertMatchesRegularExpression($this->activeRe('블로그 수집'), $html);         // 수집 활성
        $this->assertDoesNotMatchRegularExpression($this->activeRe('블로그 지수 분석'), $html); // 지수분석 비활성
    }
}
