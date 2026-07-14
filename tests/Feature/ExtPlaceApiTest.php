<?php

namespace Tests\Feature;

use App\Models\ExtToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 크롬 확장 — 플레이스 매장 분석 저장/내역 API 검증. */
class ExtPlaceApiTest extends TestCase
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
            'place_id' => '1616011574',
            'name' => '준오헤어 삼성역점',
            'keyword' => '강남 미용실',
            'cat' => 'hairshop',
            'rank' => 3,
            'n1' => 48.0,
            'n2' => 98.7,
            'n3' => 100.0,
            'visitor_cnt' => 1234,
            'blog_cnt' => 567,
            'save_cnt' => 890,
            'detail' => [
                'd' => ['d1' => 80, 'd2' => 70, 'd3' => 60, 'd4' => 90, 'd5' => null, 'd6' => 75, 'd7' => 88, 'd9' => 66, 'd10' => 55],
                'tier' => 'A',
            ],
        ], $override);
    }

    public function test_store_saves(): void
    {
        [$user, $headers] = $this->authed();

        $this->postJson('/api/ext/place-analyses', $this->payload(), $headers)
            ->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseHas('place_store_analyses', [
            'user_id' => $user->id,
            'place_id' => '1616011574',
            'keyword' => '강남 미용실',
            'rank' => 3,
        ]);
    }

    public function test_store_upserts_same_place_keyword(): void
    {
        [$user, $headers] = $this->authed();

        $this->postJson('/api/ext/place-analyses', $this->payload(), $headers)->assertOk();
        $this->postJson('/api/ext/place-analyses', $this->payload(['rank' => 1, 'n2' => 99.5]), $headers)->assertOk();

        $this->assertSame(1, $user->placeStoreAnalyses()->count());
        $this->assertDatabaseHas('place_store_analyses', ['place_id' => '1616011574', 'rank' => 1]);
    }

    public function test_store_validates(): void
    {
        [, $headers] = $this->authed();

        $this->postJson('/api/ext/place-analyses', $this->payload(['name' => '']), $headers)
            ->assertStatus(422);
        $this->postJson('/api/ext/place-analyses', $this->payload(['keyword' => '']), $headers)
            ->assertStatus(422);
        $this->postJson('/api/ext/place-analyses', $this->payload(['n2' => 200]), $headers)
            ->assertStatus(422);
    }

    public function test_index_and_show_owner_only(): void
    {
        [$user, $headers] = $this->authed();
        $mine = $user->placeStoreAnalyses()->create($this->payload());

        $this->getJson('/api/ext/place-analyses', $headers)
            ->assertOk()->assertJsonPath('data.0.place_id', '1616011574');

        $this->getJson('/api/ext/place-analyses/'.$mine->id, $headers)
            ->assertOk()->assertJsonPath('data.detail.tier', 'A');

        $other = User::create(['name' => '남', 'email' => 'o@rankfree.kr', 'password' => 'x1234567']);
        $theirs = $other->placeStoreAnalyses()->create($this->payload(['place_id' => '999']));
        $this->getJson('/api/ext/place-analyses/'.$theirs->id, $headers)->assertStatus(403);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/ext/place-analyses')->assertStatus(401);
        $this->postJson('/api/ext/place-analyses', $this->payload())->assertStatus(401);
    }
}
