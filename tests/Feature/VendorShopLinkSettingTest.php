<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 업체 쇼핑 주문 링크 설정(2026-07-25) — 배정 방식(shop_link_mode)·URL 패턴(shop_url_patterns) 라운드트립.
 * 거래처마다 주문 받는 형태가 달라 업체 단위로 둔다. 플레이스는 방식이 다를 수 있어 이 설정은 쇼핑 전용.
 */
class VendorShopLinkSettingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => '관리자', 'email' => 'vs'.uniqid().'@rf.kr', 'password' => 'x1234567', 'role' => 'super']);
    }

    public function test_defaults_to_group_mode_without_patterns(): void
    {
        $this->actingAs($this->admin())->post(route('admin.vendors.store'), [
            'name' => '기본업체', 'channel' => 'api', 'api_method' => 'POST', 'is_active' => '1',
        ])->assertSessionDoesntHaveErrors()->assertRedirect();

        $vendor = Vendor::where('name', '기본업체')->first();
        $this->assertSame('group', $vendor->shop_link_mode);   // 기존 방식(그룹 링크 순환)이 기본
        $this->assertNull($vendor->shop_url_patterns);
    }

    public function test_stores_per_keyword_mode_and_keeps_pattern_order(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.vendors.store'), [
            'name' => '개별링크업체', 'channel' => 'gsheet', 'gsheet_id' => 'sheet-1', 'is_active' => '1',
            'shop_link_mode' => 'param',
            // 빈 행은 버리고 나머지는 입력 순서 그대로 보관해야 한다(순서 = 사용 순서)
            'shop_url_patterns' => ['첫번째 형식', '  ', '두번째 형식', '세번째 형식'],
        ])->assertSessionDoesntHaveErrors()->assertRedirect();

        $vendor = Vendor::where('name', '개별링크업체')->first();
        $this->assertSame('param', $vendor->shop_link_mode);
        $this->assertSame(['첫번째 형식', '두번째 형식', '세번째 형식'], $vendor->shop_url_patterns);
    }

    public function test_update_can_switch_back_to_group_and_clear_patterns(): void
    {
        $admin = $this->admin();
        $vendor = Vendor::create([
            'name' => '수정업체', 'channel' => 'api', 'api_method' => 'POST', 'is_active' => true,
            'shop_link_mode' => 'param', 'shop_url_patterns' => ['A', 'B'],
        ]);

        $this->actingAs($admin)->put(route('admin.vendors.update', $vendor), [
            'name' => $vendor->name, 'channel' => 'api', 'api_method' => 'POST', 'is_active' => '1',
            'shop_link_mode' => 'group', 'shop_url_patterns' => ['', ''],
        ])->assertSessionDoesntHaveErrors()->assertRedirect();

        $vendor->refresh();
        $this->assertSame('group', $vendor->shop_link_mode);
        $this->assertNull($vendor->shop_url_patterns);
    }

    /** 등록 URL 순서대로(고정) 방식 — 등록한 URL 을 가공 없이 그대로 쓰는 업체. */
    public function test_stores_fixed_mode(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.vendors.store'), [
            'name' => '고정업체', 'channel' => 'api', 'api_method' => 'POST', 'is_active' => '1',
            'shop_link_mode' => 'fixed', 'shop_url_patterns' => ['https://example.test/a', 'https://example.test/b'],
        ])->assertSessionDoesntHaveErrors()->assertRedirect();

        $fixed = Vendor::where('name', '고정업체')->first();
        $this->assertSame('fixed', $fixed->shop_link_mode);
        $this->assertSame(['https://example.test/a', 'https://example.test/b'], $fixed->shop_url_patterns);

        // 등록 폼에 3가지 방식이 모두 노출되어야 한다
        $create = $this->actingAs($admin)->get(route('admin.vendors.create'))->assertOk()->getContent();
        foreach (['group', 'param', 'fixed'] as $mode) {
            $this->assertStringContainsString('value="'.$mode.'"', $create);
        }
        $this->assertStringContainsString('랜딩 URL 배정 방식', $create);
    }

    /** 파라미터 값 변경 방식 — 어떤 파라미터가 바뀌는지 이름 목록을 설정으로 보관한다. */
    public function test_stores_changed_param_keys(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.vendors.store'), [
            'name' => '파라미터업체', 'channel' => 'api', 'api_method' => 'POST', 'is_active' => '1',
            'shop_link_mode' => 'param',
            'shop_param_keys' => ['query', '  ', 'keyword'],   // 빈 행은 버리고 순서 보존
        ])->assertSessionDoesntHaveErrors()->assertRedirect();

        $vendor = Vendor::where('name', '파라미터업체')->first();
        $this->assertSame('param', $vendor->shop_link_mode);
        $this->assertSame(['query', 'keyword'], $vendor->shop_param_keys);

        // 목록 요약 · 수정 폼 복원
        $index = $this->actingAs($admin)->get(route('admin.vendors'))->assertOk()->getContent();
        $this->assertStringContainsString('바뀌는 파라미터 query, keyword', $index);

        $edit = $this->actingAs($admin)->get(route('admin.vendors.edit', $vendor))->assertOk()->getContent();
        $this->assertStringContainsString('shop_param_keys[]', $edit);
        $this->assertStringContainsString('value="query"', $edit);
    }

    public function test_rejects_unknown_link_mode(): void
    {
        $this->actingAs($this->admin())->post(route('admin.vendors.store'), [
            'name' => '이상한업체', 'channel' => 'api', 'api_method' => 'POST', 'is_active' => '1',
            'shop_link_mode' => 'whatever',
        ])->assertSessionHasErrors('shop_link_mode');

        $this->assertNull(Vendor::where('name', '이상한업체')->first());
    }

    /** 목록은 요약만, 입력 항목은 별도 폼 페이지(2026-07-25 모달 → 페이지 전환). */
    public function test_index_shows_summary_and_form_page_has_inputs(): void
    {
        $admin = $this->admin();
        $vendor = Vendor::create([
            'name' => '개별링크업체', 'channel' => 'api', 'api_method' => 'POST', 'is_active' => true,
            'shop_link_mode' => 'param', 'shop_url_patterns' => ['형식1', '형식2'],
        ]);

        // 목록 — 설정 요약 표기
        $index = $this->actingAs($admin)->get(route('admin.vendors'))->assertOk()->getContent();
        $this->assertStringContainsString('파라미터 값 변경', $index);
        $this->assertStringContainsString('랜딩 URL 2개', $index);

        // 등록 페이지 — 입력 요소
        $create = $this->actingAs($admin)->get(route('admin.vendors.create'))->assertOk()->getContent();
        $this->assertStringContainsString('name="shop_link_mode"', $create);
        $this->assertStringContainsString('shop_url_patterns[]', $create);
        $this->assertStringContainsString('쇼핑 주문 설정', $create);

        // 수정 페이지 — 저장값이 폼에 복원
        $edit = $this->actingAs($admin)->get(route('admin.vendors.edit', $vendor))->assertOk()->getContent();
        $this->assertStringContainsString('형식1', $edit);
        $this->assertStringContainsString('형식2', $edit);
        $this->assertStringContainsString('value="param" selected', $edit);
    }
}
