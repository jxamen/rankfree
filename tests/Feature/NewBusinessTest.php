<?php

namespace Tests\Feature;

use App\Domain\NewBiz\NaverLocalSearchService;
use App\Domain\NewBiz\NewBusinessPlaceMatcher;
use App\Domain\NewBiz\PlacePhoneFetcher;
use App\Models\NewBusiness;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 신규 개업(24) — 인허가 공공데이터 수집·플레이스 매칭·관리자 열람·보관기간 파기. */
class NewBusinessTest extends TestCase
{
    use RefreshDatabase;

    private ?User $admin = null;

    private function admin(): User
    {
        return $this->admin ??= User::create(['name' => '관리자', 'email' => 'nb@rf.kr', 'password' => 'x1234567', 'role' => 'super']);
    }

    /** 서울 API 응답 페이크 — 실제 컬럼 구조 그대로. ⚠️ URL 에 포트(8088)가 있어 와일드카드 패턴이 안 걸린다. */
    private function fakeApi(array $rows, int $total = null): void
    {
        Http::fake(['*openapi.seoul.go.kr*' => Http::response([
            'LOCALDATA_072404' => [
                'list_total_count' => $total ?? count($rows),
                'RESULT' => ['CODE' => 'INFO-000', 'MESSAGE' => '정상 처리되었습니다'],
                'row' => $rows,
            ],
        ], 200)]);
    }

    private function row(string $mgtNo, string $name, string $ymd, string $state = '영업/정상', string $tel = '02-123-4567'): array
    {
        return [
            'MGTNO' => $mgtNo, 'BPLCNM' => $name, 'APVPERMYMD' => $ymd.' ', 'TRDSTATENM' => $state,
            'SITETEL' => $tel, 'SITEWHLADDR' => '서울특별시 강남구 역삼동 123-4', 'RDNWHLADDR' => '서울특별시 강남구 테헤란로 1',
            'UPTAENM' => '한식', 'UPDATEGBN' => 'I', 'UPDATEDT' => '2026-07-16 10:00:00',
        ];
    }

    public function test_collect_stores_rows_with_region_and_encrypted_tel(): void
    {
        $ymd = now()->subDays(2)->toDateString();
        $this->fakeApi([$this->row('A-1', '조선솥밥', $ymd)]);

        $this->artisan('newbiz:collect', ['--days' => 1, '--no-place' => true])->assertSuccessful();

        $b = NewBusiness::first();
        $this->assertSame('조선솥밥', $b->bplc_nm);
        $this->assertSame($ymd, $b->apv_perm_ymd->toDateString());
        $this->assertSame(['서울', '강남구', '역삼동'], [$b->sido, $b->sgg, $b->emd]);
        $this->assertSame('02-123-4567', $b->site_tel);
        // 전화는 평문으로 저장되지 않는다(개인정보 취급)
        $this->assertNotSame('02-123-4567', \DB::table('new_businesses')->where('id', $b->id)->value('site_tel'));

        // 재수집은 중복 생성 없이 갱신
        $this->artisan('newbiz:collect', ['--days' => 1, '--no-place' => true])->assertSuccessful();
        $this->assertSame(1, NewBusiness::count());
    }

    /** ★ 수집과 플레이스 확인은 한 흐름 — 수집 실행만으로 방금 담은 건의 플레이스가 확인된다. */
    public function test_collect_also_matches_place_in_same_run(): void
    {
        $ymd = now()->subDays(2)->toDateString();
        $this->fakeApi([$this->row('A-9', '풍납주먹고기', $ymd)]);
        $this->mock(NaverLocalSearchService::class, fn ($m) => $m->shouldReceive('search')->andReturn([
            ['title' => '풍납 주먹고기 풍납본점', 'category' => '한식>육류,고기요리',
                'address' => '서울특별시 강남구 역삼동 123-4', 'road_address' => '서울특별시 강남구 테헤란로 1', 'link' => ''],
        ]));

        $this->artisan('newbiz:collect', ['--days' => 1])->assertSuccessful();

        $b = NewBusiness::first();
        $this->assertSame('found', $b->place_status);            // 별도 명령 없이 매칭까지 끝난다
        $this->assertSame('풍납 주먹고기 풍납본점', $b->place_name);
        $this->assertNotNull($b->place_checked_at);
    }

    /** ★ 날짜 필터가 안 먹는 업종(휴게음식점 실측)에서 엉뚱한 과거 데이터가 적재되면 안 된다. */
    public function test_collect_rejects_rows_that_do_not_match_requested_date(): void
    {
        // 요청 날짜와 무관한 2001년 행만 응답(= 필터 미적용 상황 재현)
        $this->fakeApi([$this->row('B-1', '카페네스카페', '2001-09-12', '폐업')], 146301);

        $this->artisan('newbiz:collect', ['--days' => 1])->assertSuccessful();

        $this->assertSame(0, NewBusiness::count());
    }

    /** 공식 지역검색 API 로 매칭 — 같은 시/군/구 + 상호 일치면 '있음', 아니면 '미등록'. */
    public function test_place_matcher_uses_local_search_and_marks_missing(): void
    {
        $ymd = now()->subDays(2)->toDateString();
        $found = NewBusiness::create(['source' => 'seoul', 'svc' => 'LOCALDATA_072404', 'svc_label' => '일반음식점',
            'mgt_no' => 'C-1', 'bplc_nm' => '풍납주먹고기', 'apv_perm_ymd' => $ymd, 'trd_state_nm' => '영업/정상',
            'sgg' => '송파구', 'emd' => '풍납동', 'place_status' => 'pending']);
        $missing = NewBusiness::create(['source' => 'seoul', 'svc' => 'LOCALDATA_072404', 'svc_label' => '일반음식점',
            'mgt_no' => 'C-2', 'bplc_nm' => '조선솥밥', 'apv_perm_ymd' => $ymd, 'trd_state_nm' => '영업/정상',
            'sgg' => '송파구', 'emd' => '신천동', 'place_status' => 'pending']);

        $this->mock(NaverLocalSearchService::class, function ($m) {
            // 실제 응답 형태 — 상호에 공백이 끼고 지점명이 붙는다("풍납 주먹고기 풍납본점")
            $m->shouldReceive('search')->andReturnUsing(fn (string $q) => str_contains($q, '풍납주먹고기')
                ? [['title' => '풍납 주먹고기 풍납본점', 'category' => '음식점>육류,고기',
                    'address' => '서울특별시 송파구 풍납동 1-1', 'road_address' => '서울특별시 송파구 토성로15길 3-3', 'link' => '']]
                : []);
        });

        $this->artisan('newbiz:place-match')->assertSuccessful();

        $found->refresh();
        $this->assertSame('found', $found->place_status);
        $this->assertSame('풍납 주먹고기 풍납본점', $found->place_name);
        $this->assertStringContainsString('map.naver.com/p/search/', $found->placeUrl());

        $this->assertSame('not_found', $missing->refresh()->place_status);
        $this->assertNull($missing->placeUrl());
        $this->assertStringContainsString('map.naver.com', $missing->mapSearchUrl());   // 미등록도 수동 확인 링크
    }

    /**
     * ★ 인허가에 전화가 없는 업소는 플레이스 등록 번호로 보완한다(원천 SITETEL 은 대부분 결측).
     * PlacePhoneFetcher 는 curl 직접 호출이라 여기선 대역으로 바꿔 저장·표기 계약만 검증한다.
     */
    public function test_place_phone_fills_in_when_license_tel_is_missing(): void
    {
        $biz = NewBusiness::create(['source' => 'seoul', 'svc' => 'LOCALDATA_072404', 'svc_label' => '일반음식점',
            'mgt_no' => 'P-1', 'bplc_nm' => '스시안목', 'apv_perm_ymd' => now()->subDays(2), 'trd_state_nm' => '영업/정상',
            'sido' => '서울', 'sgg' => '송파구', 'emd' => '신천동', 'place_status' => 'pending']);   // site_tel 없음

        $this->mock(NaverLocalSearchService::class, fn ($m) => $m->shouldReceive('search')->andReturn([
            ['title' => '스시안목', 'category' => '음식점>일식', 'address' => '서울특별시 송파구 신천동 1',
                'road_address' => '서울특별시 송파구 올림픽로 1', 'link' => ''],
        ]));
        $this->mock(PlacePhoneFetcher::class, fn ($m) => $m->shouldReceive('fetch')->once()->andReturn([
            'place_id' => '2008977074', 'phone' => '0507-1476-7979', 'type' => 'virtual',
        ]));

        $this->artisan('newbiz:place-match')->assertSuccessful();

        $biz->refresh();
        $this->assertSame('0507-1476-7979', $biz->place_phone);
        $this->assertSame('2008977074', $biz->place_id);
        $this->assertSame('0507-1476-7979', $biz->displayTel());   // 인허가 번호가 없으니 플레이스 번호를 보여준다
        $this->assertSame('virtual', $biz->telSource());           // 안심번호임을 구분해 표기
        // 플레이스 번호도 평문으로 저장하지 않는다
        $this->assertNotSame('0507-1476-7979', \DB::table('new_businesses')->where('id', $biz->id)->value('place_phone'));

        $html = $this->actingAs($this->admin())->get('/admin/new-businesses')->assertOk()->getContent();
        $this->assertStringContainsString('0507-1476-7979', $html);
        $this->assertStringContainsString('안심', $html);
    }

    /** 인허가에 전화가 있으면 플레이스 번호를 굳이 받아오지 않는다(불필요한 요청 방지). */
    public function test_place_phone_is_not_fetched_when_license_tel_exists(): void
    {
        $biz = NewBusiness::create(['source' => 'seoul', 'svc' => 'LOCALDATA_072404', 'svc_label' => '일반음식점',
            'mgt_no' => 'P-2', 'bplc_nm' => '부산어묵', 'apv_perm_ymd' => now()->subDays(2), 'trd_state_nm' => '영업/정상',
            'sgg' => '송파구', 'site_tel' => '02-123-4567', 'place_status' => 'pending']);

        $this->mock(NaverLocalSearchService::class, fn ($m) => $m->shouldReceive('search')->andReturn([
            ['title' => '부산어묵', 'category' => '음식점>분식', 'address' => '서울특별시 송파구 신천동 1',
                'road_address' => '서울특별시 송파구 올림픽로 1', 'link' => ''],
        ]));
        $this->mock(PlacePhoneFetcher::class, fn ($m) => $m->shouldReceive('fetch')->never());

        $this->artisan('newbiz:place-match')->assertSuccessful();

        $this->assertSame('02-123-4567', $biz->refresh()->displayTel());
        $this->assertSame('license', $biz->telSource());
    }

    /** 같은 상호가 다른 시/군/구에 있으면 '있음'으로 오판하지 않는다. */
    public function test_place_matcher_rejects_same_name_in_other_area(): void
    {
        $b = NewBusiness::create(['source' => 'seoul', 'svc' => 'LOCALDATA_072404', 'svc_label' => '일반음식점',
            'mgt_no' => 'D-1', 'bplc_nm' => '스타벅스', 'apv_perm_ymd' => now()->subDays(2), 'trd_state_nm' => '영업/정상',
            'sgg' => '송파구', 'emd' => '신천동', 'place_status' => 'pending']);

        $this->mock(NaverLocalSearchService::class, fn ($m) => $m->shouldReceive('search')->andReturn([
            ['title' => '스타벅스 강남점', 'category' => '음식점>카페', 'address' => '서울특별시 강남구 역삼동 1',
                'road_address' => '서울특별시 강남구 테헤란로 1', 'link' => ''],
        ]));

        $this->assertSame('not_found', app(NewBusinessPlaceMatcher::class)->match($b));
    }

    /** ★ 미등록은 끝이 아니다 — 개업 뒤 나중에 플레이스를 여는 경우가 많아 주기적으로 자동 재확인한다. */
    public function test_not_found_is_rechecked_after_interval_and_can_turn_found(): void
    {
        config(['rankfree.newbiz.recheck_after_days' => 3, 'rankfree.newbiz.recheck_max_age_days' => 90]);
        $mk = fn (string $mgt, string $name, $checkedAt, $ymd = null) => NewBusiness::create([
            'source' => 'seoul', 'svc' => 'LOCALDATA_072404', 'svc_label' => '일반음식점', 'mgt_no' => $mgt,
            'bplc_nm' => $name, 'apv_perm_ymd' => $ymd ?: now()->subDays(5), 'trd_state_nm' => '영업/정상',
            'sgg' => '송파구', 'emd' => '풍납동', 'place_status' => 'not_found', 'place_checked_at' => $checkedAt,
        ]);
        $due = $mk('R-1', '풍납주먹고기', now()->subDays(4));                       // 4일 전 확인 → 재확인 대상
        $fresh = $mk('R-2', '어제확인집', now()->subHours(6));                      // 오늘 확인함 → 대상 아님
        $old = $mk('R-3', '오래된집', now()->subDays(10), now()->subDays(200));     // 인허가 200일 → 그만 본다
        $found = NewBusiness::create(['source' => 'seoul', 'svc' => 'LOCALDATA_072404', 'svc_label' => '일반음식점',
            'mgt_no' => 'R-4', 'bplc_nm' => '이미있음', 'apv_perm_ymd' => now()->subDays(5), 'trd_state_nm' => '영업/정상',
            'sgg' => '송파구', 'place_status' => 'found', 'place_checked_at' => now()->subDays(30)]);

        $this->assertSame([$due->id], NewBusiness::open()->needsPlaceCheck()->pluck('id')->all());
        $this->assertNotContains($fresh->id, NewBusiness::open()->needsPlaceCheck()->pluck('id')->all());
        $this->assertNotContains($old->id, NewBusiness::open()->needsPlaceCheck()->pluck('id')->all());
        $this->assertNotContains($found->id, NewBusiness::open()->needsPlaceCheck()->pluck('id')->all());

        // 그 사이 플레이스가 열렸다면 재확인에서 '있음'으로 바뀐다
        $this->mock(NaverLocalSearchService::class, fn ($m) => $m->shouldReceive('search')->andReturn([
            ['title' => '풍납 주먹고기', 'category' => '음식점>육류,고기', 'address' => '서울특별시 송파구 풍납동 1-1',
                'road_address' => '서울특별시 송파구 토성로15길 3', 'link' => ''],
        ]));
        $this->artisan('newbiz:place-match')->assertSuccessful();

        $this->assertSame('found', $due->refresh()->place_status);
        $this->assertSame(0, NewBusiness::open()->needsPlaceCheck()->count());
    }

    /** 화면 실행은 건수 제한이 없다 — 서버가 배치만 처리하고 남은 수를 주면 화면이 0 될 때까지 이어 부른다. */
    public function test_place_match_returns_batch_progress_json_until_drained(): void
    {
        config(['rankfree.newbiz.place_match_batch' => 2]);
        foreach (range(1, 5) as $i) {
            NewBusiness::create(['source' => 'seoul', 'svc' => 'LOCALDATA_072404', 'svc_label' => '일반음식점',
                'mgt_no' => 'B-'.$i, 'bplc_nm' => '가게'.$i, 'apv_perm_ymd' => now()->subDays(2),
                'trd_state_nm' => '영업/정상', 'sgg' => '송파구', 'place_status' => 'pending']);
        }
        $this->mock(NaverLocalSearchService::class, fn ($m) => $m->shouldReceive('search')->andReturn([]));

        $seen = 0;
        for ($n = 0; $n < 5; $n++) {
            $r = $this->actingAs($this->admin())
                ->postJson('/admin/new-businesses/place-match')->assertOk()->json();
            $seen += $r['done'];
            $this->assertLessThanOrEqual(2, $r['done']);          // 요청당 배치 크기까지만
            if ($r['remaining'] === 0) {
                break;
            }
        }
        $this->assertSame(5, $seen);                              // 반복하면 전부 처리된다(상한 없음)
        $this->assertSame(0, NewBusiness::open()->needsPlaceCheck()->count());
    }

    /** ★ 주기를 안 기다리고 '전체 재확인' — 오늘 확인한 미등록도 지금 다시 보고, 루프는 반드시 끝난다. */
    public function test_force_recheck_ignores_interval_and_terminates(): void
    {
        config(['rankfree.newbiz.recheck_after_days' => 3, 'rankfree.newbiz.place_match_batch' => 2]);
        foreach (range(1, 4) as $i) {
            NewBusiness::create(['source' => 'seoul', 'svc' => 'LOCALDATA_072404', 'svc_label' => '일반음식점',
                'mgt_no' => 'F-'.$i, 'bplc_nm' => '가게'.$i, 'apv_perm_ymd' => now()->subDays(2),
                'trd_state_nm' => '영업/정상', 'sgg' => '송파구',
                // 오늘(몇 분 전) 확인함 → 재확인 주기(3일) 전이라 평상시 실행에선 대상이 아니다
                'place_status' => 'not_found', 'place_checked_at' => now()->subMinutes(5)]);
        }
        $this->mock(NaverLocalSearchService::class, fn ($m) => $m->shouldReceive('search')->andReturn([]));

        $this->assertSame(0, NewBusiness::open()->needsPlaceCheck()->count());

        // 평상시 실행은 0건(주기 전)
        $this->assertSame(0, $this->actingAs($this->admin())
            ->postJson('/admin/new-businesses/place-match')->assertOk()->json('done'));

        // force → 주기 무시하고 전부. since 를 물고 돌면 남은 수가 줄어 루프가 끝난다
        $seen = 0;
        $since = null;
        for ($n = 0; $n < 6; $n++) {
            $r = $this->actingAs($this->admin())
                ->postJson('/admin/new-businesses/place-match', array_filter(['force' => 1, 'since' => $since]))
                ->assertOk()->json();
            $since ??= $r['since'];
            $seen += $r['done'];
            if ($r['remaining'] === 0) {
                break;
            }
        }
        $this->assertSame(4, $seen);
        $this->assertSame(0, $this->actingAs($this->admin())
            ->postJson('/admin/new-businesses/place-match', ['force' => 1, 'since' => $since])->json('done'));
    }

    public function test_admin_page_requires_operator_and_lists_with_filters(): void
    {
        $ymd = now()->subDays(2)->toDateString();
        NewBusiness::create(['source' => 'seoul', 'svc' => 'LOCALDATA_072404', 'svc_label' => '일반음식점',
            'mgt_no' => 'E-1', 'bplc_nm' => '조선솥밥', 'apv_perm_ymd' => $ymd, 'trd_state_nm' => '영업/정상',
            'sido' => '서울', 'sgg' => '송파구', 'emd' => '신천동',
            'place_name' => '조선솥밥', 'place_cat' => '음식점>한식', 'place_status' => 'found',
            'place_checked_at' => now()->subDay()->setTime(9, 30)]);
        NewBusiness::create(['source' => 'seoul', 'svc' => 'LOCALDATA_072404', 'svc_label' => '일반음식점',
            'mgt_no' => 'E-2', 'bplc_nm' => '폐업집', 'apv_perm_ymd' => $ymd, 'trd_state_nm' => '폐업',
            'sido' => '서울', 'sgg' => '강남구', 'place_status' => 'pending']);

        $user = User::create(['name' => 'u', 'email' => 'plain-nb@rf.kr', 'password' => 'x1234567']);
        $this->actingAs($user)->get('/admin/new-businesses')->assertForbidden();

        $html = $this->actingAs($this->admin())->get('/admin/new-businesses')->assertOk()->getContent();
        $this->assertStringContainsString('조선솥밥', $html);
        $this->assertStringNotContainsString('폐업집', $html);                       // 영업 중만
        $this->assertStringContainsString('map.naver.com/p/search/', $html);   // 플레이스 링크(지도 검색 — 공식 API 엔 place id 없음)
        // 언제 확인한 결과인지 보여준다(미등록은 그 뒤에 플레이스가 생겼을 수 있으므로)
        $this->assertStringContainsString('최종 확인', $html);
        $this->assertStringContainsString(now()->subDay()->format('m-d').' 09:30', $html);

        // 지역 필터
        $filtered = $this->actingAs($this->admin())->get('/admin/new-businesses?sido=서울&sgg=강남구')->assertOk()->getContent();
        $this->assertStringNotContainsString('조선솥밥', $filtered);
    }

    /** 인증키는 어드민 환경 설정에서 넣는다 — 저장 시 config 를 오버라이드하고 sample 제한이 풀린다. */
    public function test_seoul_api_key_is_configurable_in_admin_settings(): void
    {
        $client = app(\App\Domain\NewBiz\SeoulLocalDataClient::class);

        // 기본(미설정) — sample 키라 일자당 5건 제한
        config(['rankfree.newbiz.seoul_key' => 'sample']);
        $this->assertTrue($client->isSampleKey());
        $this->assertSame(5, $client->pageSize());

        // 환경 설정 화면에 입력 필드가 있다
        $html = $this->actingAs($this->admin())->get('/admin/settings?tab=integ')->assertOk()->getContent();
        $this->assertStringContainsString('name="seoul_openapi_key"', $html);
        $this->assertStringContainsString('서울 열린데이터광장', $html);

        // 저장 → app_settings 에 기록(암호화) → provider 가 config 오버라이드
        $this->actingAs($this->admin())->put('/admin/settings', [
            'tab' => 'integ', 'seoul_openapi_key' => '테스트인증키1234',
        ])->assertRedirect();
        $this->assertSame('테스트인증키1234', \App\Models\AppSetting::read('seoul.openapi_key'));
        $this->assertNotSame('테스트인증키1234', \DB::table('app_settings')->where('key', 'seoul.openapi_key')->value('value'));

        (new \App\Providers\SettingsServiceProvider($this->app))->boot();
        $this->assertSame('테스트인증키1234', config('rankfree.newbiz.seoul_key'));
        $this->assertFalse($client->isSampleKey());
        $this->assertSame(1000, $client->pageSize());   // 정식 키 → 페이지네이션 해제

        // 실제 요청 URL 에 설정한 키가 쓰인다
        Http::fake(['*openapi.seoul.go.kr*' => Http::response(['LOCALDATA_072404' => [
            'list_total_count' => 0, 'RESULT' => ['CODE' => 'INFO-000'], 'row' => [],
        ]], 200)]);
        $client->fetch('LOCALDATA_072404', '2026-07-15', 1, 5);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/테스트인증키1234/json/') || str_contains(rawurldecode($r->url()), '/테스트인증키1234/json/'));
    }

    /** 목록 기본은 전체(기간 제한 없음, 2026-07-24) — 기간을 고르면 그때만 좁힌다. */
    public function test_index_lists_all_by_default_and_filters_by_days(): void
    {
        NewBusiness::create(['source' => 'seoul', 'svc' => 'LOCALDATA_072404', 'svc_label' => '일반음식점',
            'mgt_no' => 'OLD1', 'bplc_nm' => '아주오래된식당', 'trd_state_nm' => '영업/정상',
            'apv_perm_ymd' => now()->subDays(60)->toDateString()]);
        NewBusiness::create(['source' => 'seoul', 'svc' => 'LOCALDATA_072404', 'svc_label' => '일반음식점',
            'mgt_no' => 'NEW1', 'bplc_nm' => '갓개업식당', 'trd_state_nm' => '영업/정상',
            'apv_perm_ymd' => now()->subDays(3)->toDateString()]);

        // 기본(전체) — 60일 전 것도 나온다
        $html = $this->actingAs($this->admin())->get('/admin/new-businesses')->assertOk()->getContent();
        $this->assertStringContainsString('아주오래된식당', $html);
        $this->assertStringContainsString('갓개업식당', $html);
        $this->assertStringContainsString('>전체</option>', $html);

        // 최근 7일 필터 — 오래된 것은 빠진다
        $html = $this->actingAs($this->admin())->get('/admin/new-businesses?days=7')->assertOk()->getContent();
        $this->assertStringNotContainsString('아주오래된식당', $html);
        $this->assertStringContainsString('갓개업식당', $html);
    }
}
