<?php

namespace Tests\Feature;

use App\Models\KeywordCandidate;
use App\Models\KeywordCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 관리자 키워드 탐색 — 플레이스 지역 3단계(시/도 › 시/군/구 › 지역), 공개 /keywords/place 와 동일 계층. */
class KeywordBrowseTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => '관리자', 'email' => 'browse@rf.kr', 'password' => 'x1234567', 'role' => 'super']);
    }

    private function seedPlace(): KeywordCategory
    {
        $cat = KeywordCategory::create(['type' => 'place', 'name' => '맛집·음식점', 'slug' => '맛집-음식점', 'is_active' => true]);
        foreach ([
            ['강남역 맛집', '강남역', 'hotplace'],   // 서울 > 강남구
            ['역삼동 맛집', '역삼동', 'dong'],       // 서울 > 강남구
            ['홍대 맛집', '홍대', 'hotplace'],       // 서울 > 마포구
            ['가경동 맛집', '가경동', 'dong'],       // 충북 > 청주시
        ] as [$kw, $rg, $rt]) {
            KeywordCandidate::create([
                'category_id' => $cat->id, 'keyword' => $kw, 'region' => $rg, 'region_type' => $rt,
                'source' => 'combo', 'status' => 'pending',
            ]);
        }

        return $cat;
    }

    public function test_place_region_selects_are_three_level(): void
    {
        $this->seedPlace();
        $admin = $this->admin();

        // 1단계 — 시/도 옵션(서울 3 · 충북 1), 하위는 비활성
        $html = $this->actingAs($admin)->get('/admin/keyword-browse?type=place')->assertOk()->getContent();
        $this->assertStringContainsString('>시/도<', $html);
        $this->assertStringContainsString('서울 (3)', $html);
        $this->assertStringContainsString('충북 (1)', $html);
        $this->assertStringNotContainsString('지역유형', $html);   // 구 UI 제거

        // 2단계 — 서울 선택 시 시/군/구 채움 + 서울 키워드만
        $html = $this->actingAs($admin)->get('/admin/keyword-browse?type=place&sido=서울')->assertOk()->getContent();
        $this->assertStringContainsString('강남구 (2)', $html);
        $this->assertStringContainsString('마포구 (1)', $html);
        $this->assertStringContainsString('강남역 맛집', $html);
        $this->assertStringNotContainsString('가경동 맛집', $html);

        // 3단계 — 강남구 선택 시 지역 옵션 + 그 지역 키워드
        $html = $this->actingAs($admin)->get('/admin/keyword-browse?type=place&sido=서울&sgg=강남구')->assertOk()->getContent();
        $this->assertStringContainsString('강남역 (1)', $html);
        $this->assertStringContainsString('역삼동 맛집', $html);
        $this->assertStringNotContainsString('홍대 맛집', $html);

        // 지역 확정
        $html = $this->actingAs($admin)->get('/admin/keyword-browse?type=place&sido=서울&sgg=강남구&rg=강남역')->assertOk()->getContent();
        $this->assertStringContainsString('강남역 맛집', $html);
        $this->assertStringNotContainsString('역삼동 맛집', $html);
    }

    public function test_invalid_region_selection_is_dropped(): void
    {
        $this->seedPlace();

        // 잘못된 조합(서울에 없는 시군구)은 조용히 해제 — 오류 없이 상위 결과
        $html = $this->actingAs($this->admin())
            ->get('/admin/keyword-browse?type=place&sido=서울&sgg=청주시&rg=가경동')->assertOk()->getContent();
        $this->assertStringContainsString('강남역 맛집', $html);
        $this->assertStringNotContainsString('가경동 맛집', $html);
    }

    public function test_shopping_keeps_category_tree(): void
    {
        $root = KeywordCategory::create(['type' => 'shopping', 'name' => '패션잡화', 'slug' => '패션잡화', 'is_active' => true]);
        $sub = KeywordCategory::create(['type' => 'shopping', 'name' => '여성신발', 'slug' => '여성신발', 'parent_id' => $root->id, 'is_active' => true]);
        KeywordCandidate::create(['category_id' => $sub->id, 'keyword' => '젤리슈즈', 'source' => 'datalab', 'status' => 'pending']);

        $html = $this->actingAs($this->admin())->get('/admin/keyword-browse?type=shopping&c1='.$root->id)->assertOk()->getContent();
        $this->assertStringContainsString('여성신발', $html);   // 2분류 옵션
        $this->assertStringContainsString('젤리슈즈', $html);   // 손자까지 스코프
        $this->assertStringNotContainsString('시/도', $html);   // 쇼핑엔 지역 축 없음
    }
}
