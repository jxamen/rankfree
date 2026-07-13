<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 메뉴 SEO — 타이틀/디스크립션/키워드 저장·해석·페이지 메타 렌더. */
class MenuSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_seo_resolves_and_prefers_site_area(): void
    {
        // 마이그레이션이 시드하지 않는 고유 라우트로 우선순위 검증
        Menu::create(['area' => 'console', 'name' => 'C', 'route' => 'x.custom', 'meta_title' => '콘솔용']);
        Menu::create(['area' => 'site', 'name' => 'S', 'route' => 'x.custom', 'meta_title' => '사이트용', 'meta_description' => '설명', 'meta_keywords' => 'a, b']);

        $seo = Menu::seo('x.custom');
        $this->assertSame('사이트용', $seo['title']);   // site 우선
        $this->assertSame('설명', $seo['description']);
        $this->assertSame('a, b', $seo['keywords']);

        $this->assertSame(['title' => null, 'description' => null, 'keywords' => null], Menu::seo('no.such.route'));
        $this->assertSame(['title' => null, 'description' => null, 'keywords' => null], Menu::seo(null));
    }

    public function test_migration_seeds_home_site_seo_and_renders_on_homepage(): void
    {
        // 마이그레이션이 home site 행을 시드 → 홈페이지에 메타로 렌더
        $home = Menu::where('area', 'site')->where('route', 'home')->first();
        $this->assertNotNull($home, '마이그레이션이 home 사이트 SEO 행을 시드해야 함');

        $res = $this->get('/');
        $res->assertOk()
            ->assertSee($home->meta_title, false)
            ->assertSee('name="keywords"', false);
    }

    public function test_admin_editable_seo_reflects_on_public_page(): void
    {
        Menu::where('area', 'site')->where('route', 'home')->update([
            'meta_title' => 'RF_TEST_TITLE',
            'meta_description' => 'RF_TEST_DESC',
            'meta_keywords' => 'kwone, kwtwo',
        ]);

        $this->get('/')
            ->assertSee('RF_TEST_TITLE', false)
            ->assertSee('RF_TEST_DESC', false)
            ->assertSee('kwone, kwtwo', false);
    }

    public function test_admin_update_saves_meta_keywords(): void
    {
        $menu = Menu::create(['area' => 'console', 'name' => '순위 추적', 'route' => 'console.rank']);
        $super = User::create(['name' => 's', 'email' => 's@rf.kr', 'password' => 'x1234567', 'role' => 'super']);

        $this->actingAs($super)->put("/admin/menus/{$menu->id}", [
            'name' => '순위 추적',
            'meta_title' => '플레이스 순위 추적 · rankfree',
            'meta_description' => '키워드별 순위를 매일 추적합니다.',
            'meta_keywords' => '플레이스 순위, 순위 추적',
        ])->assertRedirect();

        $this->assertDatabaseHas('menus', [
            'id' => $menu->id, 'meta_keywords' => '플레이스 순위, 순위 추적',
            'meta_title' => '플레이스 순위 추적 · rankfree',
        ]);
    }
}
