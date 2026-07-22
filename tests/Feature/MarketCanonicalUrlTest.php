<?php

namespace Tests\Feature;

use App\Models\MarketAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 시장분석 키워드당 정식 URL 하나(2026-07-22) — 같은 키워드의 -2·-13 등 파생 슬러그는
 * 첫 문서 슬러그로 301 통합되고, 어떤 경로로 들어와도 그 키워드의 최신 데이터를 보여준다.
 */
class MarketCanonicalUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_suffixed_slug_redirects_to_canonical_and_shows_latest_data(): void
    {
        $u = User::create(['name' => 'u', 'email' => 'mc@rf.kr', 'password' => 'x1234567']);
        $old = MarketAnalysis::create(['user_id' => $u->id, 'keyword' => '여름이불', 'sales_6m' => 111,
            'snapshot' => ['top_products' => [['title' => '옛날 이불', 'price' => 10000, 'purchase6m' => 1]]]]);
        $new = MarketAnalysis::create(['user_id' => $u->id, 'keyword' => '여름이불', 'sales_6m' => 98765,
            'snapshot' => ['top_products' => [['title' => '최신 여름이불 베스트', 'price' => 30000, 'purchase6m' => 9]]]]);
        $new->forceFill(['updated_at' => now()->addMinute()])->save();

        $this->assertSame('여름이불', $old->slug);
        $this->assertSame('여름이불-2', $new->slug);

        // 파생 슬러그 → 정식 URL 301
        $this->get('/market/'.rawurlencode('여름이불-2'))
            ->assertStatus(301)
            ->assertRedirect(route('market.shared', '여름이불'));

        // 정식 URL 은 그 키워드의 최신 데이터를 보여준다
        $this->get('/market/'.rawurlencode('여름이불'))
            ->assertOk()
            ->assertSee('최신 여름이불 베스트')
            ->assertSee(number_format(98765));
    }

    public function test_share_token_also_lands_on_canonical(): void
    {
        $u = User::create(['name' => 'u', 'email' => 'mc2@rf.kr', 'password' => 'x1234567']);
        $old = MarketAnalysis::create(['user_id' => $u->id, 'keyword' => '킹크랩', 'snapshot' => ['top_products' => []]]);
        $new = MarketAnalysis::create(['user_id' => $u->id, 'keyword' => '킹크랩', 'snapshot' => ['top_products' => []]]);

        $this->get('/market/'.$new->shareToken())
            ->assertStatus(301)
            ->assertRedirect(route('market.shared', $old->slug));
    }
}
