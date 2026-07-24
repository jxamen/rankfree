<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 업체 '주말 몰아 발주'(weekend_batch_dispatch) 설정(2026-07-24) — 등록·수정 라운드트립, 기본값(꺼짐), 화면 노출.
 * 실제 발주 도래일 앞당김(금요일)은 [OrderWeekendBatchDispatchTest] 에서 검증한다.
 */
class VendorWeekendSettingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => '관리자', 'email' => 'vw'.uniqid().'@rf.kr', 'password' => 'x1234567', 'role' => 'super']);
    }

    public function test_default_off_and_toggle_on_off(): void
    {
        $admin = $this->admin();

        // 미체크(hidden 0 만) → 꺼짐(false, 정상 당일 발주)
        $this->actingAs($admin)->post(route('admin.vendors.store'), [
            'name' => '정상업체', 'channel' => 'api', 'api_method' => 'POST',
            'is_active' => '1', 'weekend_batch_dispatch' => '0',
        ])->assertSessionDoesntHaveErrors()->assertRedirect();
        $this->assertFalse(Vendor::where('name', '정상업체')->first()->weekend_batch_dispatch);

        // 체크 → 켜짐(true, 주말 몰아 발주)
        $this->actingAs($admin)->post(route('admin.vendors.store'), [
            'name' => '주말몰아업체', 'channel' => 'gsheet', 'gsheet_id' => 'abc123',
            'is_active' => '1', 'weekend_batch_dispatch' => '1',
        ])->assertSessionDoesntHaveErrors()->assertRedirect();
        $vendor = Vendor::where('name', '주말몰아업체')->first();
        $this->assertTrue($vendor->weekend_batch_dispatch);

        // 수정으로 다시 끄기
        $this->actingAs($admin)->put(route('admin.vendors.update', $vendor), [
            'name' => $vendor->name, 'channel' => 'gsheet', 'gsheet_id' => 'abc123',
            'is_active' => '1', 'weekend_batch_dispatch' => '0',
        ])->assertSessionDoesntHaveErrors()->assertRedirect();
        $this->assertFalse($vendor->refresh()->weekend_batch_dispatch);
    }

    public function test_index_shows_weekend_batch_toggle_and_flag(): void
    {
        $admin = $this->admin();
        Vendor::create(['name' => '주말몰아업체', 'channel' => 'api', 'api_method' => 'POST', 'is_active' => true, 'weekend_batch_dispatch' => true]);

        $html = $this->actingAs($admin)->get(route('admin.vendors'))->assertOk()->getContent();
        $this->assertStringContainsString('name="weekend_batch_dispatch"', $html);
        $this->assertStringContainsString('id="vd-weekend"', $html);
        $this->assertStringContainsString('주말 몰아 발주', $html);
    }
}
