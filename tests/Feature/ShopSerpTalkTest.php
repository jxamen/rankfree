<?php

namespace Tests\Feature;

use App\Models\{ExtToken, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 확장이 보낸 톡톡 코드가 저장되고 상세에서 톡톡 링크로 열리는지 — 확장 → API → 저장 → 화면 전 경로. */
class ShopSerpTalkTest extends TestCase
{
    use RefreshDatabase;

    public function test_talk_id_is_saved_and_rendered(): void
    {
        $u = User::create(['name' => 'a', 'email' => 'talk@rf.kr', 'password' => 'x1234567', 'role' => 'super']);
        [, $plain] = ExtToken::issue($u);

        $this->postJson('/api/ext/keyword-shop-serp', [
            'keyword' => '린넨원피스',
            'total' => 1234,
            'products' => [[
                'title' => '국내제작 여성 린넨 원피스', 'rank' => 1, 'price' => 39000,
                'mallName' => '멜빈트', 'link' => 'https://smartstore.naver.com/melvint/products/9',
                'talkId' => 'w4n5gg', 'storeId' => 'melvint',
            ]],
        ], ['Authorization' => 'Bearer '.$plain])->assertOk();

        $this->assertSame('w4n5gg', DB::table('shop_products')->value('talk_id'), '톡톡 코드가 저장돼야 한다');

        $this->actingAs($u)->get('/admin/keyword-browse/detail?keyword=린넨원피스')
            ->assertOk()
            ->assertSee('talk.naver.com/ct/w4n5gg')   // 클릭하면 톡톡이 열린다
            ->assertSee('국내제작 여성 린넨 원피스');
    }
}
