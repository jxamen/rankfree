<?php

namespace Tests\Feature;

use App\Models\ShopRankRecord;
use App\Models\ShopRankSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 쇼핑 순위추적 — 엑셀(XLSX) 다운로드. */
class ShopRankExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_returns_xlsx(): void
    {
        $user = User::create(['name' => 'u', 'email' => 's@rf.kr', 'password' => 'x1234567']);
        $slot = ShopRankSlot::create([
            'user_id' => $user->id, 'keyword' => '여름매트', 'target_type' => 'product',
            'product_id' => '82000', 'product_title' => '쿨매트', 'is_active' => true,
        ]);
        ShopRankRecord::create(['slot_id' => $slot->id, 'rank' => 12, 'price' => 19900, 'list_total' => 3000, 'checked_date' => '2026-07-14', 'created_at' => now()]);

        $res = $this->actingAs($user)->get('/console/shop-rank/export');
        $res->assertOk();
        $res->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('rankfree_shoprank_', $res->headers->get('content-disposition'));
        // 유효한 xlsx(zip) 시그니처
        $this->assertStringStartsWith('PK', $res->streamedContent());
    }

    public function test_export_button_shown_only_with_slots(): void
    {
        $user = User::create(['name' => 'u', 'email' => 's2@rf.kr', 'password' => 'x1234567']);
        // 슬롯 없음 → 버튼 숨김
        $this->actingAs($user)->get('/console/shop-rank')->assertOk()->assertDontSee('엑셀 다운로드');
        // 슬롯 있으면 노출
        ShopRankSlot::create(['user_id' => $user->id, 'keyword' => 'k', 'target_type' => 'mall', 'mall_name' => '스토어', 'is_active' => true]);
        $this->actingAs($user)->get('/console/shop-rank')->assertOk()->assertSee('엑셀 다운로드');
    }
}
