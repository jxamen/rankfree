<?php

namespace Tests\Feature;

use App\Domain\Place\SmartplaceCollector;
use App\Domain\Place\SmartplaceLoginService;
use App\Models\SmartplaceAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 스마트플레이스 리포트 수집 콘솔 — 아이디/비번 자동 로그인 방식. 수집기·로그인은 목으로 대체(네트워크 없음). */
class SmartplaceTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email = 'tester@rankfree.kr'): User
    {
        return User::create(['name' => '테스터', 'email' => $email, 'password' => 'secret1234']);
    }

    private function fakeResult(bool $loggedIn = true, int $refinedCode = 200): array
    {
        return [
            'placeSeq' => '3488833',
            'ids' => ['placeSeq' => '3488833', 'placeId' => '1137930547', 'siteId' => 'sp_abc123', 'businessId' => '627457', 'name' => '라온헤어', 'httpCode' => $refinedCode],
            'name' => $loggedIn ? '라온헤어' : '',
            'category' => '미용실',
            'period' => ['2026-07-04', '2026-07-10'],
            'bearerOk' => $loggedIn,
            'loggedIn' => $loggedIn,
            'refinedCode' => $refinedCode,
            'collectedAt' => '2026-07-10T12:00:00+09:00',
            'sections' => [
                'stats' => [
                    'date_time' => ['label' => '일자별 조회수', 'status' => 200, 'data' => [['date_time' => '2026-07-09', 'pv' => 12], ['date_time' => '2026-07-10', 'pv' => 20]]],
                    'channel' => ['label' => '유입 채널', 'status' => 200, 'data' => [['mapped_channel_name' => '검색', 'pv' => 30]]],
                ],
                'review_visitor' => ['label' => '방문자 리뷰', 'status' => 200, 'data' => ['data' => ['reviews' => ['totalCount' => 5, 'items' => [['author' => ['displayName' => '방문자1'], 'rating' => 5, 'visitDateTime' => '2026-07-09T10:00:00', 'content' => ['text' => '좋아요']]]]]]],
                'review_blog' => ['label' => '블로그 리뷰', 'status' => 200, 'data' => ['data' => ['fsasReviews' => ['total' => 2, 'items' => [['type' => 'blog', 'url' => 'https://blog.naver.com/x/1', 'title' => '후기']]]]]],
                'smartcall_count' => ['label' => '스마트콜 통화수', 'status' => 200, 'data' => ['total' => 3, 'success' => 2]],
                'smartcall_callers' => ['label' => '스마트콜 발신자', 'status' => 200, 'data' => ['total' => 1, 'docs' => [['callerTel' => '010-0000-0000', 'callCount' => 1, 'lastCsTime' => '2026-07-10T09:00:00']]]],
                'booking_users' => ['label' => '예약/주문 고객', 'status' => 200, 'data' => ['businessUserCount' => 4, 'businessUserList' => [['name' => '고객1', 'sex' => '여', 'ageGroup' => '30대', 'phone' => '010-1111-2222']]]],
            ],
        ];
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/console/smartplace')->assertRedirect('/login');
    }

    public function test_store_saves_selected_place_seq_and_encrypts_password(): void
    {
        $user = $this->makeUser();

        $res = $this->actingAs($user)->post('/console/smartplace', [
            'label' => '라온헤어',
            'place_seq' => '3488833',
            'business_id' => '627457',
            'category' => '미용실',
            'naver_id' => 'owner_naver',
            'naver_pw' => 'sup3r-secret',
        ]);

        $res->assertRedirect();
        $acc = $user->smartplaceAccounts()->first();
        $this->assertSame('3488833', $acc->place_seq);
        $this->assertSame('627457', $acc->business_id);
        $this->assertSame('owner_naver', $acc->naver_id);
        $this->assertSame('sup3r-secret', $acc->naver_pw);
        // 비밀번호는 DB에 평문으로 저장되지 않는다 (encrypted cast)
        $raw = DB::table('smartplace_accounts')->where('id', $acc->id)->value('naver_pw');
        $this->assertStringNotContainsString('sup3r-secret', (string) $raw);
    }

    public function test_store_resolves_place_url_and_name(): void
    {
        $user = $this->makeUser();

        // 플레이스 URL 입력 → resolvePlace 로 m.place 정규화·업체명·업종 자동 (네트워크 없이 목킹)
        $this->mock(\App\Domain\Place\RankSlotService::class, function ($m) {
            $m->shouldReceive('resolvePlace')->once()->with('https://map.naver.com/p/entry/place/1137930547')
                ->andReturn(['place_id' => '1137930547', 'place_url' => 'https://m.place.naver.com/hairshop/1137930547', 'place_name' => '라온헤어', 'category' => 'hairshop']);
        });

        $this->actingAs($user)->post('/console/smartplace', [
            'label' => '라온헤어',
            'place_seq' => '3488833',
            'place' => 'https://map.naver.com/p/entry/place/1137930547',
            'naver_id' => 'owner',
            'naver_pw' => 'pw',
        ])->assertRedirect();

        $acc = $user->smartplaceAccounts()->first();
        $this->assertSame('1137930547', $acc->place_id);
        $this->assertSame('https://m.place.naver.com/hairshop/1137930547', $acc->place_url);
        $this->assertSame('hairshop', $acc->category);
    }

    public function test_store_requires_credentials(): void
    {
        $user = $this->makeUser();

        $res = $this->actingAs($user)->from('/console/smartplace')->post('/console/smartplace', [
            'label' => '라온헤어',
            'place_seq' => '3488833',
            'naver_id' => '',
            'naver_pw' => '',
        ]);

        $res->assertSessionHasErrors(['naver_id', 'naver_pw']);
        $this->assertSame(0, $user->smartplaceAccounts()->count());
    }

    public function test_store_requires_place_seq_selection(): void
    {
        $user = $this->makeUser();

        $res = $this->actingAs($user)->from('/console/smartplace')->post('/console/smartplace', [
            'label' => '라온헤어',
            'naver_id' => 'owner',
            'naver_pw' => 'pw',
        ]);

        $res->assertSessionHasErrors('place_seq');
        $this->assertSame(0, $user->smartplaceAccounts()->count());
    }

    public function test_update_keeps_password_when_blank(): void
    {
        $user = $this->makeUser();
        $acc = $user->smartplaceAccounts()->create([
            'label' => '라온헤어', 'place_seq' => '3488833', 'naver_id' => 'owner', 'naver_pw' => 'keep-me',
        ]);

        $this->actingAs($user)->put("/console/smartplace/{$acc->id}", [
            'label' => '라온헤어 본점',
            'place_seq' => '3488833',
            'category' => '',
            'naver_id' => 'owner',
            'naver_pw' => '',
        ])->assertRedirect();

        $acc->refresh();
        $this->assertSame('라온헤어 본점', $acc->label);
        $this->assertSame('keep-me', $acc->naver_pw);
    }

    public function test_update_discards_session_when_credentials_change(): void
    {
        $user = $this->makeUser();
        $acc = $user->smartplaceAccounts()->create([
            'label' => '라온헤어', 'place_seq' => '3488833', 'naver_id' => 'owner', 'naver_pw' => 'pw',
            'cookie' => 'NID_AUT=old-session', 'logged_in_at' => now(),
        ]);

        $this->actingAs($user)->put("/console/smartplace/{$acc->id}", [
            'label' => '라온헤어',
            'place_seq' => '3488833',
            'naver_id' => 'owner2', // 아이디 변경 → 세션 폐기
            'naver_pw' => '',
        ])->assertRedirect();

        $acc->refresh();
        $this->assertSame('owner2', $acc->naver_id);
        $this->assertNull($acc->cookie);
        $this->assertNull($acc->logged_in_at);
    }

    public function test_update_denies_other_users_account(): void
    {
        $owner = $this->makeUser();
        $acc = $owner->smartplaceAccounts()->create(['label' => 'A', 'place_seq' => '1', 'naver_id' => 'o', 'naver_pw' => 'p']);
        $other = $this->makeUser('other@rankfree.kr');

        $this->actingAs($other)->put("/console/smartplace/{$acc->id}", [
            'label' => 'B', 'place_seq' => '1', 'naver_id' => 'x',
        ])->assertForbidden();
    }

    public function test_edit_button_exposes_stored_password_for_owner(): void
    {
        // 요청사항: 수정 화면에서 저장된 비밀번호를 복호화해 채워 보여준다(소유자 본인 화면).
        $user = $this->makeUser();
        $user->smartplaceAccounts()->create([
            'label' => '라온헤어', 'place_seq' => '3488833', 'naver_id' => 'owner', 'naver_pw' => 'my-secret-pw',
        ]);

        $this->actingAs($user)->get('/console/smartplace')
            ->assertOk()
            ->assertSee('data-naver-pw="my-secret-pw"', false);
    }

    public function test_collect_uses_saved_session_without_login(): void
    {
        $user = $this->makeUser();
        $acc = $user->smartplaceAccounts()->create([
            'label' => '라온헤어', 'place_seq' => '3488833', 'naver_id' => 'owner', 'naver_pw' => 'pw',
            'cookie' => 'NID_AUT=valid-session',
        ]);

        // 저장된 세션이 유효 → 로그인 서비스는 호출되지 않아야 한다
        $this->mock(SmartplaceLoginService::class, function ($m) {
            $m->shouldNotReceive('login');
        });
        $this->mock(SmartplaceCollector::class, function ($m) {
            $m->shouldReceive('collect')->once()->andReturn($this->fakeResult());
        });

        $res = $this->actingAs($user)->postJson("/console/smartplace/{$acc->id}/collect", []);

        $res->assertOk()->assertJson(['ok' => true, 'name' => '라온헤어']);
        $this->assertStringContainsString('통계 2/6', $res->json('summary'));
        $acc->refresh();
        $this->assertSame('OK', $acc->last_status);
        $this->assertSame('1137930547', $acc->place_id);
    }

    public function test_collect_auto_logs_in_when_no_session(): void
    {
        $user = $this->makeUser();
        $acc = $user->smartplaceAccounts()->create([
            'label' => '라온헤어', 'place_seq' => '3488833', 'naver_id' => 'owner', 'naver_pw' => 'pw',
        ]);

        // 세션 없음 → 로그인 서비스가 쿠키를 채운다
        $this->mock(SmartplaceLoginService::class, function ($m) use ($acc) {
            $m->shouldReceive('login')->once()->andReturnUsing(function () use ($acc) {
                SmartplaceAccount::whereKey($acc->id)->update(['cookie' => encrypt('NID_AUT=fresh')]);

                return ['ok' => true, 'cookie' => 'NID_AUT=fresh'];
            });
        });
        $this->mock(SmartplaceCollector::class, function ($m) {
            $m->shouldReceive('collect')->once()->andReturn($this->fakeResult());
        });

        $res = $this->actingAs($user)->postJson("/console/smartplace/{$acc->id}/collect", []);

        $res->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('OK', $acc->refresh()->last_status);
    }

    public function test_collect_retries_login_when_session_expired(): void
    {
        $user = $this->makeUser();
        $acc = $user->smartplaceAccounts()->create([
            'label' => '라온헤어', 'place_seq' => '3488833', 'naver_id' => 'owner', 'naver_pw' => 'pw',
            'cookie' => 'NID_AUT=expired',
        ]);

        // 1차 수집(만료 쿠키) 실패 → 로그인 → 2차 수집 성공
        $this->mock(SmartplaceLoginService::class, function ($m) use ($acc) {
            $m->shouldReceive('login')->once()->andReturnUsing(function () use ($acc) {
                SmartplaceAccount::whereKey($acc->id)->update(['cookie' => encrypt('NID_AUT=fresh')]);

                return ['ok' => true, 'cookie' => 'NID_AUT=fresh'];
            });
        });
        $this->mock(SmartplaceCollector::class, function ($m) {
            $m->shouldReceive('collect')->once()->andReturn($this->fakeResult(loggedIn: false, refinedCode: 401));
            $m->shouldReceive('collect')->once()->andReturn($this->fakeResult());
        });

        $res = $this->actingAs($user)->postJson("/console/smartplace/{$acc->id}/collect", []);

        $res->assertOk()->assertJson(['ok' => true]);
    }

    public function test_collect_reports_login_failure(): void
    {
        $user = $this->makeUser();
        $acc = $user->smartplaceAccounts()->create([
            'label' => '라온헤어', 'place_seq' => '3488833', 'naver_id' => 'owner', 'naver_pw' => 'pw',
        ]);

        $this->mock(SmartplaceLoginService::class, function ($m) {
            $m->shouldReceive('login')->once()->andReturn(['ok' => false, 'reason' => '네이버 로그인이 캡차/2차 인증에 막혔습니다.']);
        });

        $res = $this->actingAs($user)->postJson("/console/smartplace/{$acc->id}/collect", []);

        $res->assertStatus(422);
        $this->assertStringContainsString('자동 로그인 실패', $res->json('message'));
        $this->assertSame('FAIL', $acc->refresh()->last_status);
    }

    public function test_collect_requires_credentials(): void
    {
        $user = $this->makeUser();
        $acc = $user->smartplaceAccounts()->create(['label' => '라온헤어', 'place_seq' => '3488833']);

        $this->actingAs($user)->postJson("/console/smartplace/{$acc->id}/collect", [])->assertStatus(422);
    }

    public function test_discover_returns_single_business_for_autoselect(): void
    {
        $user = $this->makeUser();

        $this->mock(SmartplaceLoginService::class, function ($m) {
            $m->shouldReceive('login')->once()->with(\Mockery::type(SmartplaceAccount::class), false)
                ->andReturn(['ok' => true, 'cookie' => 'NID_AUT=fresh']);
        });
        $this->mock(SmartplaceCollector::class, function ($m) {
            $m->shouldReceive('listBusinesses')->once()->with('NID_AUT=fresh')->andReturn([
                'ok' => true, 'httpCode' => 200,
                'businesses' => [
                    ['placeSeq' => '3488833', 'name' => '라온헤어', 'placeId' => '1137930547', 'businessId' => '627457', 'raw' => []],
                ],
            ]);
        });

        $res = $this->actingAs($user)->postJson('/console/smartplace/discover', [
            'naver_id' => 'owner', 'naver_pw' => 'pw',
        ]);

        $res->assertOk()->assertJson(['ok' => true, 'count' => 1]);
        $this->assertSame('3488833', $res->json('businesses.0.placeSeq'));
        $this->assertSame('627457', $res->json('businesses.0.businessId'));
        $this->assertSame('라온헤어', $res->json('businesses.0.name'));
        $this->assertSame(0, $user->smartplaceAccounts()->count()); // 조회는 저장하지 않는다
    }

    public function test_discover_returns_multiple_businesses(): void
    {
        $user = $this->makeUser();

        $this->mock(SmartplaceLoginService::class, function ($m) {
            $m->shouldReceive('login')->once()->andReturn(['ok' => true, 'cookie' => 'c']);
        });
        $this->mock(SmartplaceCollector::class, function ($m) {
            $m->shouldReceive('listBusinesses')->once()->andReturn([
                'ok' => true, 'httpCode' => 200,
                'businesses' => [
                    ['placeSeq' => '111', 'name' => '가게A', 'placeId' => 'a', 'businessId' => '', 'raw' => []],
                    ['placeSeq' => '222', 'name' => '가게B', 'placeId' => 'b', 'businessId' => '', 'raw' => []],
                ],
            ]);
        });

        $res = $this->actingAs($user)->postJson('/console/smartplace/discover', [
            'naver_id' => 'owner', 'naver_pw' => 'pw',
        ]);

        $res->assertOk()->assertJson(['ok' => true, 'count' => 2]);
        $this->assertCount(2, $res->json('businesses'));
    }

    public function test_discover_reports_login_failure(): void
    {
        $user = $this->makeUser();

        $this->mock(SmartplaceLoginService::class, function ($m) {
            $m->shouldReceive('login')->once()->andReturn(['ok' => false, 'reason' => '캡차에 막혔습니다.']);
        });

        $res = $this->actingAs($user)->postJson('/console/smartplace/discover', [
            'naver_id' => 'owner', 'naver_pw' => 'pw',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('자동 로그인 실패', $res->json('message'));
    }

    public function test_discover_requires_credentials(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/console/smartplace/discover', [])->assertStatus(422);
    }

    public function test_report_renders_five_tabs_from_last_result(): void
    {
        $user = $this->makeUser();
        $acc = $user->smartplaceAccounts()->create([
            'label' => '라온헤어', 'place_seq' => '3488833', 'naver_id' => 'o', 'naver_pw' => 'p',
            'last_result' => $this->fakeResult(), 'last_status' => 'OK',
        ]);

        $res = $this->actingAs($user)->get("/console/smartplace/{$acc->id}/report");

        $res->assertOk()
            ->assertSee('방문 전 지표')
            ->assertSee('플레이스')
            ->assertSee('스마트콜')
            ->assertSee('예약·주문')
            ->assertSee('방문자1')
            ->assertSee('라온헤어');
    }

    public function test_report_booking_tab_aggregates_customer_analysis(): void
    {
        $user = $this->makeUser();
        $result = $this->fakeResult();
        // 예약 고객 여러 명 → 성별·연령·유입경로 집계 검증
        $result['sections']['booking_users']['data'] = [
            'businessUserCount' => 3,
            'businessUserList' => [
                ['name' => '고객1', 'sex' => '여성', 'ageGroup' => '30대', 'phone' => '010-1', 'initialEntry' => '네이버검색', 'visitCount' => 2],
                ['name' => '고객2', 'sex' => '남성', 'ageGroup' => '40대', 'phone' => '010-2', 'initialEntry' => '네이버검색', 'visitCount' => 1],
                ['name' => '고객3', 'sex' => '여성', 'ageGroup' => '30대', 'phone' => '010-3', 'initialEntry' => '지도', 'birthday' => '05-01'],
            ],
        ];
        $acc = $user->smartplaceAccounts()->create([
            'label' => '라온헤어', 'place_seq' => '3488833', 'naver_id' => 'o', 'naver_pw' => 'p',
            'last_result' => $result, 'last_status' => 'OK',
        ]);

        $this->actingAs($user)->get("/console/smartplace/{$acc->id}/report")
            ->assertOk()
            ->assertSee('예약·주문 요약')
            ->assertSee('유입경로')
            ->assertSee('연령대 분포')
            ->assertSee('성별 분포')
            ->assertSee('네이버검색')  // 유입경로 집계 라벨
            ->assertSee('30대')        // 연령 집계 라벨
            ->assertDontSee('연결 예정'); // 더는 미구현 안내가 아니어야 한다
    }

    public function test_report_booking_tab_empty_when_no_reservation(): void
    {
        $user = $this->makeUser();
        $result = $this->fakeResult();
        $result['sections']['booking_users'] = ['label' => '예약/주문 고객', 'status' => 0, 'data' => null, 'skip' => '예약 미사용(businessId 없음)'];
        $acc = $user->smartplaceAccounts()->create([
            'label' => '라온헤어', 'place_seq' => '3488833', 'naver_id' => 'o', 'naver_pw' => 'p',
            'last_result' => $result, 'last_status' => 'OK',
        ]);

        $this->actingAs($user)->get("/console/smartplace/{$acc->id}/report")
            ->assertOk()
            ->assertSee('예약 미사용');
    }

    public function test_report_shows_empty_state_before_collect(): void
    {
        $user = $this->makeUser();
        $acc = $user->smartplaceAccounts()->create(['label' => '라온헤어', 'place_seq' => '3488833', 'naver_id' => 'o', 'naver_pw' => 'p']);

        $this->actingAs($user)->get("/console/smartplace/{$acc->id}/report")
            ->assertOk()
            ->assertSee('아직 수집된 리포트가 없습니다');
    }

    public function test_report_denies_other_users_account(): void
    {
        $owner = $this->makeUser();
        $acc = $owner->smartplaceAccounts()->create(['label' => 'A', 'place_seq' => '1', 'naver_id' => 'o', 'naver_pw' => 'p', 'last_result' => $this->fakeResult()]);
        $other = $this->makeUser('other@rankfree.kr');

        $this->actingAs($other)->get("/console/smartplace/{$acc->id}/report")->assertForbidden();
    }

    public function test_destroy_removes_account(): void
    {
        $user = $this->makeUser();
        $acc = $user->smartplaceAccounts()->create(['label' => 'A', 'place_seq' => '1', 'naver_id' => 'o', 'naver_pw' => 'p']);

        $this->actingAs($user)->delete("/console/smartplace/{$acc->id}")->assertRedirect();
        $this->assertSame(0, $user->smartplaceAccounts()->count());
    }
}
