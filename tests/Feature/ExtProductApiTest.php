<?php

namespace Tests\Feature;

use App\Models\ExtToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 크롬 확장 — 상품 분석(리뷰) 저장/내역 API 검증. */
class ExtProductApiTest extends TestCase
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
            'origin_product_no' => 218004002,
            'merchant_no' => 500000438,
            'name' => '테스트 상품',
            'url' => 'https://smartstore.naver.com/e-sandl/products/218004002',
            'store' => 'e-sandl',
            'total_reviews' => 1234,
            'analyzed_reviews' => 300,
            'avg_score' => 4.65,
            'repurchase_pct' => 12.3,
            'recent_7d' => 5,
            'recent_1m' => 40,
            'recent_3m' => 120,
            'sales_6m' => 3000,
            'price' => 15900,
            'snapshot' => [
                'dist' => ['5' => 250, '4' => 30, '3' => 10, '2' => 5, '1' => 5],
                'options' => [['블랙 / L', 120], ['화이트 / M', 80]],
                'opt_total' => 200,
                'weak_words' => [['배송', 8], ['불량', 5]],
                'worst_samples' => [['text' => '배송이 느려요', 'score' => 1]],
                'low_reviews' => 20,
            ],
        ], $override);
    }

    public function test_store_saves(): void
    {
        [$user, $headers] = $this->authed();

        $this->postJson('/api/ext/product-analyses', $this->payload(), $headers)
            ->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseHas('product_analyses', [
            'user_id' => $user->id,
            'origin_product_no' => 218004002,
            'sales_6m' => 3000,
        ]);
    }

    public function test_store_validates(): void
    {
        [, $headers] = $this->authed();

        $this->postJson('/api/ext/product-analyses', $this->payload(['name' => '']), $headers)
            ->assertStatus(422);
        $this->postJson('/api/ext/product-analyses', $this->payload(['avg_score' => 9]), $headers)
            ->assertStatus(422);
    }

    public function test_index_and_show_owner_only(): void
    {
        [$user, $headers] = $this->authed();
        $mine = $user->productAnalyses()->create($this->payload());

        $this->getJson('/api/ext/product-analyses', $headers)
            ->assertOk()->assertJsonPath('data.0.origin_product_no', 218004002);

        $this->getJson('/api/ext/product-analyses/'.$mine->id, $headers)
            ->assertOk()->assertJsonPath('data.snapshot.options.0.0', '블랙 / L');

        $other = User::create(['name' => '남', 'email' => 'o@rankfree.kr', 'password' => 'x1234567']);
        $theirs = $other->productAnalyses()->create($this->payload(['name' => '남의것']));
        $this->getJson('/api/ext/product-analyses/'.$theirs->id, $headers)->assertStatus(403);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/ext/product-analyses')->assertStatus(401);
        $this->postJson('/api/ext/product-analyses', $this->payload())->assertStatus(401);
    }
}
