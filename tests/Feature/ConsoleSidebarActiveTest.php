<?php

namespace Tests\Feature;

use App\Models\BlogIndexAnalysis;
use App\Models\Menu;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 콘솔 사이드바 — 상세(show 등) 하위 라우트에서도 부모 메뉴 활성(하이라이트) 유지. */
class ConsoleSidebarActiveTest extends TestCase
{
    use RefreshDatabase;

    /** 사이드바에서 해당 URL 링크가 활성 클래스(.sb-link.on)를 갖는지 정규식으로 검사. */
    private function assertSidebarActive(string $html, string $href, bool $active): void
    {
        $link = preg_quote($href, '/');
        $found = (bool) preg_match('/<a href="'.$link.'"[^>]*class="sb-link[^"]*\bon\b/', $html);
        $this->assertSame($active, $found, "sidebar link {$href} active=".var_export($active, true));
    }

    public function test_menu_stays_active_on_detail_route(): void
    {
        $user = User::create(['name' => 'u', 'email' => 'u@rf.kr', 'password' => 'x1234567']);
        Menu::create(['area' => 'console', 'name' => '블로그 지수 분석', 'route' => 'console.blog', 'sort_order' => 0, 'is_active' => true]);
        Menu::create(['area' => 'console', 'name' => '키워드 분석', 'route' => 'console.keyword', 'sort_order' => 1, 'is_active' => true]);
        $analysis = BlogIndexAnalysis::create([
            'user_id' => $user->id, 'type' => 'keyword', 'query' => '강남 맛집', 'title' => '강남 맛집',
            'score' => 70, 'blogger_count' => 0, 'snapshot' => ['keyword' => '강남 맛집', 'bloggers' => []],
        ]);

        // 목록 라우트 — 자기 메뉴 활성
        $html = $this->actingAs($user)->get('/console/blog-collect')->assertOk()->getContent();
        $this->assertSidebarActive($html, route('console.blog'), true);
        $this->assertSidebarActive($html, route('console.keyword'), false);

        // 상세 라우트(console.blog.show) — 부모 메뉴(console.blog) 활성 유지, 다른 메뉴는 비활성
        $html = $this->actingAs($user)->get('/console/blog-index/'.$analysis->id)->assertOk()->getContent();
        $this->assertSidebarActive($html, route('console.blog'), true);
        $this->assertSidebarActive($html, route('console.keyword'), false);
    }
}
