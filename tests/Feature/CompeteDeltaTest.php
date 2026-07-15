<?php

namespace Tests\Feature;

use App\Models\PlaceRankSlot;
use App\Models\PlaceSeoScore;
use App\Models\PlaceSeoSerp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 경쟁분석 상세 — 직전 분석일 대비 지표 변화량(델타) 표시. */
class CompeteDeltaTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_renders_delta_vs_previous_day(): void
    {
        $user = User::create(['name' => 'u', 'email' => 'c@rf.kr', 'password' => 'x1234567']);
        $slot = PlaceRankSlot::create([
            'user_id' => $user->id, 'keyword' => '여름매트', 'place_id' => '12345', 'place_name' => '내매장', 'is_active' => true,
        ]);

        // 2일치 스냅샷(내 매장): 어제 → 오늘. n1 60→70(+10), 리뷰 100→130(+30), 순위 5→3(개선)
        foreach ([['2026-07-13', 60, 100, 5], ['2026-07-14', 70, 130, 3]] as [$ymd, $n1, $vis, $rnk]) {
            PlaceSeoSerp::create([
                'slot_id' => $slot->id, 'ymd' => $ymd, 'rnk' => $rnk, 'place_id' => '12345', 'name' => '내매장',
                'visitor_cnt' => $vis, 'blog_cnt' => 10, 'review_score' => 4.5, 'is_mine' => true, 'created_at' => now(),
            ]);
            PlaceSeoScore::create([
                'slot_id' => $slot->id, 'place_id' => '12345', 'ymd' => $ymd, 'rnk' => $rnk,
                'n1' => $n1, 'n2' => 50, 'n3' => 80, 'tier' => 2, 'is_mine' => true, 'created_at' => now(),
            ]);
        }

        $html = $this->actingAs($user)->get('/console/compete/'.$slot->id)->assertOk()->getContent();

        // 상승 델타(▲)와 범례가 렌더돼야 함 (n1·리뷰·순위 모두 개선 → ▲)
        $this->assertStringContainsString('▲', $html);
        $this->assertStringContainsString('직전 분석일', $html);
    }

    public function test_show_without_previous_day_has_no_delta_legend(): void
    {
        $user = User::create(['name' => 'u', 'email' => 'c2@rf.kr', 'password' => 'x1234567']);
        $slot = PlaceRankSlot::create([
            'user_id' => $user->id, 'keyword' => '여름매트', 'place_id' => '999', 'place_name' => '내매장', 'is_active' => true,
        ]);
        // 하루치만 — 델타 범례 없어야 함
        PlaceSeoSerp::create(['slot_id' => $slot->id, 'ymd' => '2026-07-14', 'rnk' => 3, 'place_id' => '999', 'name' => '내매장', 'visitor_cnt' => 130, 'blog_cnt' => 10, 'review_score' => 4.5, 'is_mine' => true, 'created_at' => now()]);
        PlaceSeoScore::create(['slot_id' => $slot->id, 'place_id' => '999', 'ymd' => '2026-07-14', 'rnk' => 3, 'n1' => 70, 'n2' => 50, 'n3' => 80, 'tier' => 2, 'is_mine' => true, 'created_at' => now()]);

        $html = $this->actingAs($user)->get('/console/compete/'.$slot->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('직전 분석일', $html);
    }
}
