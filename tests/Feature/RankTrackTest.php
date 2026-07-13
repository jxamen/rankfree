<?php

namespace Tests\Feature;

use App\Models\PlaceRankRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 순위 추적 콘솔 — 수정·엑셀 다운로드 검증 (네트워크를 타지 않는 경로만). */
class RankTrackTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email = 'tester@rankfree.kr'): User
    {
        return User::create(['name' => '테스터', 'email' => $email, 'password' => 'secret1234']);
    }

    public function test_update_changes_keyword_and_label(): void
    {
        $user = $this->makeUser();
        $slot = $user->rankSlots()->create(['keyword' => '강남 미용실', 'place_id' => '123', 'place_name' => '라온헤어', 'category' => 'hairshop']);

        // place 를 기존 place_id 그대로 보내면 재조회 없이 키워드/라벨만 수정된다
        $res = $this->actingAs($user)->put("/console/rank/{$slot->id}", [
            'keyword' => '역삼 미용실',
            'place' => '123',
            'label' => '본점',
        ]);

        $res->assertRedirect();
        $slot->refresh();
        $this->assertSame('역삼 미용실', $slot->keyword);
        $this->assertSame('본점', $slot->label);
        $this->assertSame('123', $slot->place_id);
    }

    public function test_update_rejects_duplicate_keyword_on_same_place(): void
    {
        $user = $this->makeUser();
        $user->rankSlots()->create(['keyword' => '역삼 미용실', 'place_id' => '123', 'place_name' => '라온헤어', 'category' => 'hairshop']);
        $slot = $user->rankSlots()->create(['keyword' => '강남 미용실', 'place_id' => '123', 'place_name' => '라온헤어', 'category' => 'hairshop']);

        $res = $this->actingAs($user)->from('/console/rank')->put("/console/rank/{$slot->id}", [
            'keyword' => '역삼 미용실',
            'place' => '123',
        ]);

        $res->assertSessionHasErrors('keyword');
        $this->assertSame('강남 미용실', $slot->refresh()->keyword);
    }

    public function test_update_denies_other_users_slot(): void
    {
        $owner = $this->makeUser();
        $slot = $owner->rankSlots()->create(['keyword' => '강남 미용실', 'place_id' => '123', 'category' => 'place']);
        $other = $this->makeUser('other@rankfree.kr');

        $this->actingAs($other)
            ->put("/console/rank/{$slot->id}", ['keyword' => '탈취 시도', 'place' => '123'])
            ->assertForbidden();
    }

    /** 기간 검색 — 지정 기간의 순위 기록만 표시된다. */
    public function test_period_filter_shows_only_range(): void
    {
        $user = $this->makeUser();
        $slot = $user->rankSlots()->create(['keyword' => '강남 미용실', 'place_id' => '123', 'category' => 'place']);
        foreach ([['2026-07-01', 5], ['2026-07-10', 9]] as [$d, $rk]) {
            PlaceRankRecord::create([
                'slot_id' => $slot->id, 'rank' => $rk, 'review_count' => 10, 'blog_review_count' => 1,
                'save_count' => null, 'list_total' => 300, 'checked_date' => $d, 'created_at' => now(),
            ]);
        }

        $this->actingAs($user)->get('/console/rank?from=2026-07-05&to=2026-07-12')
            ->assertOk()
            ->assertSee('07-10')
            ->assertDontSee('>07-01<', false);
    }

    /** 키워드 필터 — 일치하는 슬롯만 표시된다. (placeholder 문구와 겹치지 않는 키워드 사용) */
    public function test_keyword_filter_narrows_slots(): void
    {
        $user = $this->makeUser();
        $user->rankSlots()->create(['keyword' => '역삼 헬스장', 'place_id' => '1', 'category' => 'place']);
        $user->rankSlots()->create(['keyword' => '수원 카페', 'place_id' => '2', 'category' => 'place']);

        $this->actingAs($user)->get('/console/rank?q=수원')
            ->assertOk()
            ->assertSee('수원 카페')
            ->assertDontSee('역삼 헬스장');
    }

    public function test_export_downloads_xlsx(): void
    {
        $user = $this->makeUser();
        $slot = $user->rankSlots()->create(['keyword' => '강남 미용실', 'place_id' => '123', 'place_name' => '라온헤어', 'category' => 'restaurant']);
        PlaceRankRecord::create([
            'slot_id' => $slot->id, 'rank' => 7, 'review_count' => 186, 'blog_review_count' => 10,
            'save_count' => 55, 'list_total' => 300, 'checked_date' => '2026-07-10', 'created_at' => now(),
        ]);

        $res = $this->actingAs($user)->get('/console/rank/export');

        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $res->headers->get('content-type'));
        // XLSX = zip 컨테이너 (PK 시그니처)
        $this->assertStringStartsWith('PK', $res->streamedContent());
    }

    public function test_shared_report_is_public(): void
    {
        $user = $this->makeUser();
        $slot = $user->rankSlots()->create([
            'keyword' => '강남 미용실', 'place_id' => '123', 'place_name' => '라온헤어',
            'category' => 'place', 'share_token' => 'test-share-token-1234567890abcdef',
        ]);
        PlaceRankRecord::create([
            'slot_id' => $slot->id, 'rank' => 7, 'review_count' => 186, 'blog_review_count' => 10,
            'save_count' => null, 'list_total' => 300, 'checked_date' => '2026-07-10', 'created_at' => now(),
        ]);

        // 비로그인 열람
        $this->get('/r/test-share-token-1234567890abcdef')
            ->assertOk()
            ->assertSee('강남 미용실')
            ->assertSee('7위');

        // 잘못된 토큰은 404
        $this->get('/r/no-such-token')->assertNotFound();
    }

    /** 회귀: 'date' 캐스트가 'Y-m-d H:i:s'로 저장돼 당일 upsert가 유니크 위반을 내던 버그. */
    public function test_daily_record_upserts_without_unique_violation(): void
    {
        $user = $this->makeUser();
        $slot = $user->rankSlots()->create(['keyword' => '강남 미용실', 'place_id' => '123', 'category' => 'place']);

        foreach ([7, 9] as $rank) {
            PlaceRankRecord::updateOrCreate(
                ['slot_id' => $slot->id, 'checked_date' => now()->toDateString()],
                ['rank' => $rank, 'review_count' => 100, 'blog_review_count' => 5, 'save_count' => null, 'list_total' => 300, 'created_at' => now()],
            );
        }

        $this->assertDatabaseCount('place_rank_records', 1);
        $this->assertSame(9, PlaceRankRecord::first()->rank);
    }
}
