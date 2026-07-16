<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 셀러력 상세(처방 마케팅 재매핑) + 공개 공유 리포트 검증. */
class SellerPowerShareTest extends TestCase
{
    use RefreshDatabase;

    private function analysis(User $user)
    {
        return $user->sellerPowerAnalyses()->create([
            'keyword' => '헤드셋', 'product_url' => 'https://smartstore.naver.com/x/products/1',
            'product_name' => '테스트 헤드셋', 'store_id' => 'main',
            'score' => 60, 'grade' => 'D', 'market_percentile' => 80, 'rank_in_top' => 9, 'competitor_count' => 10,
            'snapshot' => [
                'keyword' => '헤드셋', 'product_name' => '테스트 헤드셋', 'score' => 60, 'grade' => 'D',
                'market_percentile' => 80, 'rank_in_top' => 9, 'competitor_count' => 10,
                'axes' => [], 'radar_avg_total' => 66, 'positions' => [80, 70, 60], 'my_position_index' => 2,
                'losses' => [],
                'rx' => [
                    ['axis' => '기본·배송', 'items' => [
                        ['state' => 'ok', 'name' => '무료배송', 'tip' => '설정됨 — 좋아요'],
                        ['state' => 'warn', 'name' => '포인트 지급액', 'tip' => '99원 · 상위 평균 171원 — 보강 필요'],
                    ]],
                ],
            ],
        ]);
    }

    public function test_show_remaps_point_to_marketing(): void
    {
        $user = User::create(['name' => 'u', 'email' => 'u@rf.kr', 'password' => 'x1234567']);
        $a = $this->analysis($user);

        $res = $this->actingAs($user)->get('/console/seller-power/'.$a->id);
        $res->assertOk()
            ->assertSee('테스트 헤드셋')
            ->assertSee('마케팅·판매자')   // remapRx로 새 그룹 생성
            ->assertSee('포인트 지급액');
    }

    /** 콘솔 상세 우측 상단에 공유 버튼(공개 링크 복사)이 렌더된다. */
    public function test_show_renders_share_button(): void
    {
        $user = User::create(['name' => 'u', 'email' => 'btn@rf.kr', 'password' => 'x1234567']);
        $a = $this->analysis($user);

        $this->actingAs($user)->get('/console/seller-power/'.$a->id)
            ->assertOk()
            ->assertSee('🔗 공유')
            ->assertSee('rfCopyShare', false)
            // @js() 가 슬래시를 이스케이프하므로 SEO 슬러그 경로 접두(\/seller\/)로 확인
            ->assertSee('\/seller\/', false);
    }

    public function test_public_share_opens_without_login(): void
    {
        $user = User::create(['name' => 'u', 'email' => 'u@rf.kr', 'password' => 'x1234567']);
        $a = $this->analysis($user);

        // 구 토큰 URL → 슬러그로 301
        $this->get('/sp/'.$a->shareToken())->assertStatus(301)->assertRedirect($a->shareUrl());

        $res = $this->get($a->shareUrl());
        $res->assertOk()
            ->assertSee('테스트 헤드셋')
            ->assertSee('셀러력 진단 리포트')
            ->assertDontSee('id="rf-sidebar"', false);
    }

    public function test_bad_token_404(): void
    {
        $this->get('/sp/nope-nope')->assertNotFound();
    }
}
