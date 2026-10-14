<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\ShopRankRecord;
use App\Models\ShopRankSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 쇼핑 순위 읽기 API(2026-10-14, 셀프 마케팅 앱): 목록 · 라벨 필터 · 상세 · 남의 슬롯 차단. */
class ShopRankReadApiTest extends TestCase
{
    use RefreshDatabase;

    private function slot(User $u, string $kw, string $label, bool $active = true, ?int $rank = 5): ShopRankSlot
    {
        $s = ShopRankSlot::create(['user_id' => $u->id, 'keyword' => $kw, 'label' => $label, 'is_active' => $active, 'last_rank' => $rank,
            'product_id' => '111', 'product_title' => '내 상품', 'mall_name' => '내몰', 'product_url' => 'https://x/111', 'last_checked_at' => now()]);
        ShopRankRecord::create(['slot_id' => $s->id, 'rank' => $rank, 'price' => 1000, 'list_total' => 100, 'checked_date' => now()->toDateString(), 'created_at' => now()]);

        return $s;
    }

    public function test_목록은_키_주인의_슬롯과_사용량을_준다(): void
    {
        $me = User::factory()->create(['role' => 'super']);
        $other = User::factory()->create();
        $a = $this->slot($me, '강아지 사료', 'app:u1');
        $this->slot($other, '남의 키워드', 'app:u1');
        [, $plain] = ApiKey::issue($me, '셀프 마케팅', ['shop_keyword'], null, null, null);

        $res = $this->withToken($plain)->getJson('/api/v1/shop-rank/slots')->assertOk();

        $res->assertJsonPath('used', 1)->assertJsonCount(1, 'slots')
            ->assertJsonPath('slots.0.id', $a->id)->assertJsonPath('slots.0.last_rank', 5)
            ->assertJsonPath('slots.0.history.0.rank', 5);
    }

    public function test_라벨과_활성_필터와_기록_생략(): void
    {
        $me = User::factory()->create(['role' => 'super']);
        $this->slot($me, 'k1', 'app:u1');
        $this->slot($me, 'k2', 'app:u2');
        $this->slot($me, 'k3', 'app:u1', active: false);
        [, $plain] = ApiKey::issue($me, '셀프 마케팅', ['shop_keyword'], null, null, null);

        $this->withToken($plain)->getJson('/api/v1/shop-rank/slots?label=app:u1')->assertOk()->assertJsonCount(2, 'slots');
        $this->withToken($plain)->getJson('/api/v1/shop-rank/slots?label=app:u1&active=1')->assertOk()->assertJsonCount(1, 'slots');
        $this->withToken($plain)->getJson('/api/v1/shop-rank/slots?history=0')->assertOk()->assertJsonPath('slots.0.history', null);
    }

    public function test_상세는_본인_슬롯만_보여준다(): void
    {
        $me = User::factory()->create(['role' => 'super']);
        $other = User::factory()->create();
        $mine = $this->slot($me, 'k1', 'x');
        $theirs = $this->slot($other, 'k2', 'x');
        [, $plain] = ApiKey::issue($me, '셀프 마케팅', ['shop_keyword'], null, null, null);

        $this->withToken($plain)->getJson("/api/v1/shop-rank/slots/{$mine->id}")->assertOk()->assertJsonPath('slot.keyword', 'k1');
        $this->withToken($plain)->getJson("/api/v1/shop-rank/slots/{$theirs->id}")->assertNotFound();
    }

    public function test_범위가_없는_키는_거절된다(): void
    {
        $me = User::factory()->create(['role' => 'super']);
        [, $plain] = ApiKey::issue($me, '키워드만', ['keyword'], null, null, null);

        $this->withToken($plain)->getJson('/api/v1/shop-rank/slots')->assertStatus(403);
    }
}
