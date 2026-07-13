<?php

namespace Tests\Feature;

use App\Models\ExtToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 크롬 확장 — 시장 분석 저장/내역 API 검증. */
class ExtMarketApiTest extends TestCase
{
    use RefreshDatabase;

    private function authed(): array
    {
        $user = User::create([
            'name' => '테스터',
            'email' => 'tester@rankfree.kr',
            'password' => 'secret1234',
        ]);
        [, $plain] = ExtToken::issue($user);

        return [$user, ['Authorization' => 'Bearer '.$plain]];
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'keyword' => '고구마',
            'total_count' => 572000,
            'item_count' => 160,
            'include_ads' => false,
            'sales_6m' => 12345,
            'revenue_6m' => 987654321,
            'avg_price' => 15900,
            'median_price' => 12900,
            'top10_share' => 42.5,
            'monthly_search' => 42870,
            'comp_idx' => '중간',
            'snapshot' => [
                'related_tags' => ['호박고구마', '꿀고구마'],
                'keyword_data' => ['monthly_total' => 42870],
                'top_products' => [
                    ['title' => '해남 꿀고구마 3kg', 'price' => 15900, 'purchase6m' => 500, 'mallName' => '테스트몰'],
                ],
                'pages' => 2,
            ],
        ], $override);
    }

    public function test_store_saves_analysis(): void
    {
        [$user, $headers] = $this->authed();

        $res = $this->postJson('/api/ext/market-analyses', $this->payload(), $headers);

        $res->assertOk()->assertJsonPath('ok', true);
        $this->assertDatabaseHas('market_analyses', [
            'user_id' => $user->id,
            'keyword' => '고구마',
            'revenue_6m' => 987654321,
        ]);
    }

    public function test_store_validates_keyword(): void
    {
        [, $headers] = $this->authed();

        $this->postJson('/api/ext/market-analyses', $this->payload(['keyword' => '']), $headers)
            ->assertStatus(422);
    }

    public function test_index_lists_own_analyses_only(): void
    {
        [$user, $headers] = $this->authed();
        $user->marketAnalyses()->create($this->payload());

        $other = User::create(['name' => '남', 'email' => 'other@rankfree.kr', 'password' => 'x1234567']);
        $other->marketAnalyses()->create($this->payload(['keyword' => '남의것']));

        $res = $this->getJson('/api/ext/market-analyses', $headers);

        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('고구마', $res->json('data.0.keyword'));
    }

    public function test_show_returns_snapshot_and_blocks_others(): void
    {
        [$user, $headers] = $this->authed();
        $mine = $user->marketAnalyses()->create($this->payload());

        $this->getJson('/api/ext/market-analyses/'.$mine->id, $headers)
            ->assertOk()
            ->assertJsonPath('data.snapshot.related_tags.0', '호박고구마');

        $other = User::create(['name' => '남', 'email' => 'other@rankfree.kr', 'password' => 'x1234567']);
        $theirs = $other->marketAnalyses()->create($this->payload(['keyword' => '남의것']));

        $this->getJson('/api/ext/market-analyses/'.$theirs->id, $headers)->assertStatus(403);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/ext/market-analyses')->assertStatus(401);
        $this->postJson('/api/ext/market-analyses', $this->payload())->assertStatus(401);
    }
}
