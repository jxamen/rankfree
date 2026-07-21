<?php

namespace Tests\Feature;

use App\Domain\Keyword\NaverContentVolumeService;
use App\Domain\Keyword\NaverKeywordService;
use App\Models\AppSetting;
use App\Models\User;
use App\Providers\SettingsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 환경 설정 — 네이버 API 자격증명 다중 관리, 암호화 저장, config 런타임 오버라이드, 계정 로테이션. */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function super(): User
    {
        return User::create(['name' => 'a', 'email' => 'admin@rf.kr', 'password' => 'x1234567', 'role' => 'super']);
    }

    private function reboot(): void
    {
        (new SettingsServiceProvider($this->app))->boot();
    }

    public function test_settings_page_loads_for_operator(): void
    {
        // 저장된 값(시크릿 포함)이 폼 value 로 렌더되어 보기/복사 가능해야 함
        AppSetting::write('searchad.accounts', json_encode([['api_key' => 'AKSHOW', 'customer_id' => '9', 'secret_key' => 'SECSHOW']]));

        $this->actingAs($this->super())->get('/admin/settings')
            ->assertOk()->assertSee('네이버 검색광고 API')->assertSee('광고주 로그인')->assertSee('데이터랩')
            ->assertSee('AKSHOW')->assertSee('SECSHOW'); // 시크릿도 value 로 노출(가림은 프런트 type=password)
    }

    /** 회귀: 구글 연동 시 '연동 해제' 폼이 메인 저장 폼 안에 중첩되면 </form> 이 메인 폼을 조기 종료해
     *  저장 버튼·이후 필드가 폼 밖으로 빠진다 → 저장이 한 번에 안 됨. 해제 폼은 메인 폼 밖에 있어야 한다. */
    public function test_google_disconnect_form_not_nested_in_settings_form(): void
    {
        AppSetting::write(\App\Support\GoogleToken::KEY_REFRESH, 'dummy-refresh-token'); // googleConnected = true
        AppSetting::write(\App\Support\GoogleToken::KEY_EMAIL, 'owner@rf.kr');

        $html = $this->actingAs($this->super())->get('/admin/settings')->assertOk()->getContent();

        // '연동 해제' 버튼은 외부 폼을 form 속성으로 참조(중첩 아님)
        $this->assertStringContainsString('form="rf-gdisconnect"', $html);

        $mainOpen = strpos($html, 'id="rf-settings-form"');
        $mainClose = strpos($html, '</form>', $mainOpen); // 중첩이 없으면 이게 메인 폼의 닫힘
        $saveBtn = strpos($html, '>저장</button>');
        $disconnectForm = strpos($html, 'id="rf-gdisconnect"');

        $this->assertNotFalse($mainOpen);
        $this->assertNotFalse($disconnectForm);
        // 저장 버튼은 메인 폼 안(닫힘보다 앞), 해제 폼은 메인 폼 밖(닫힘보다 뒤)
        $this->assertLessThan($mainClose, $saveBtn, '저장 버튼이 메인 폼 밖으로 빠졌습니다(폼 중첩 회귀).');
        $this->assertGreaterThan($mainClose, $disconnectForm, '연동 해제 폼이 메인 폼 안에 중첩됐습니다.');
    }

    public function test_non_operator_forbidden(): void
    {
        $user = User::create(['name' => 'u', 'email' => 'u@rf.kr', 'password' => 'x1234567']);
        $this->actingAs($user)->get('/admin/settings')->assertForbidden();
    }

    public function test_save_multiple_credentials_and_override_config(): void
    {
        $this->actingAs($this->super())->put('/admin/settings', [
            // 검색광고 2계정
            'searchad_api_key' => ['AK1', 'AK2'],
            'searchad_customer_id' => ['11', '22'],
            'searchad_secret_key' => ['S1', 'S2'],
            // 광고주 로그인 2개
            'ads_id' => ['ads1', 'ads2'],
            'ads_pw' => ['pw1', 'pw2'],
            // OpenAPI 2개
            'openapi_id' => ['cidA', 'cidB'],
            'openapi_secret' => ['secA', 'secB'],
        ])->assertRedirect(route('admin.settings'));

        $this->assertCount(2, AppSetting::readJson('searchad.accounts'));
        $this->assertCount(2, AppSetting::readJson('ads.logins'));
        $this->assertCount(2, AppSetting::readJson('openapi.keys'));

        $this->reboot();
        // 대표 계정이 단일 config 로 반영 + 전체 리스트
        $this->assertSame('AK1', config('rankfree.searchad.api_key'));
        $this->assertSame('11', config('rankfree.searchad.customer_id'));
        $this->assertCount(2, config('rankfree.searchad.accounts'));
        $this->assertSame('ads1', config('searchadweb.login.id'));
        $this->assertCount(2, config('searchadweb.logins'));
        $this->assertCount(2, config('rankfree.shopping.api_keys'));
        $this->assertSame('cidA', config('rankfree.shopping.api_keys.0.id'));
    }

    public function test_secrets_encrypted_at_rest(): void
    {
        AppSetting::write('searchad.accounts', json_encode([['api_key' => 'AK', 'customer_id' => '9', 'secret_key' => 'PLAINSECRET']]));
        $raw = DB::table('app_settings')->where('key', 'searchad.accounts')->value('value');
        $this->assertStringNotContainsString('PLAINSECRET', (string) $raw);
        // 복호화 조회는 원문
        $this->assertSame('PLAINSECRET', AppSetting::readJson('searchad.accounts')[0]['secret_key']);
    }

    public function test_existing_secret_is_editable(): void
    {
        $super = $this->super();
        $this->actingAs($super)->put('/admin/settings', [
            'openapi_id' => ['id1'], 'openapi_secret' => ['old'],
        ]);
        // 같은 id 로 시크릿만 교체 저장 → 새 값으로 갱신
        $this->actingAs($super)->put('/admin/settings', [
            'openapi_id' => ['id1'], 'openapi_secret' => ['NEWSECRET'],
        ]);
        $keys = AppSetting::readJson('openapi.keys');
        $this->assertCount(1, $keys);
        $this->assertSame('NEWSECRET', $keys[0]['secret']);
    }

    public function test_delete_by_omission_removes_row(): void
    {
        $super = $this->super();
        // 2줄 저장
        $this->actingAs($super)->put('/admin/settings', [
            'openapi_id' => ['id1', 'id2'], 'openapi_secret' => ['s1', 's2'],
        ]);
        // 프런트에서 1번 줄을 삭제 → 전송에 아예 없음(=id1 만 전송)
        $this->actingAs($super)->put('/admin/settings', [
            'openapi_id' => ['id1'], 'openapi_secret' => ['s1'],
        ]);
        $keys = AppSetting::readJson('openapi.keys');
        $ids = array_column($keys, 'id');
        $this->assertCount(1, $keys);
        $this->assertSame(['id1'], $ids);
    }

    public function test_empty_group_clears_all(): void
    {
        $super = $this->super();
        $this->actingAs($super)->put('/admin/settings', ['openapi_id' => ['id1'], 'openapi_secret' => ['s1']]);
        $this->assertCount(1, AppSetting::readJson('openapi.keys'));
        // 전 줄 삭제 → 그룹 필드 미전송 → 비워짐
        $this->actingAs($super)->put('/admin/settings', []);
        $this->assertCount(0, AppSetting::readJson('openapi.keys'));
    }

    public function test_new_entry_requires_all_fields(): void
    {
        $super = $this->super();
        // api_key 만 있고 secret 없음 → 저장 안 됨
        $this->actingAs($super)->put('/admin/settings', [
            'searchad_api_key' => ['AK'], 'searchad_customer_id' => ['11'], 'searchad_secret_key' => [''],
        ]);
        $this->assertCount(0, AppSetting::readJson('searchad.accounts'));
    }

    public function test_cloudflare_settings_are_saved(): void
    {
        $this->actingAs($this->super())->put('/admin/settings', [
            'tab' => 'domains',
            'cloudflare_api_token' => 'Bearer cf-token',
            'cloudflare_dns_target' => 'https://rankfree.kr/app',
            'cloudflare_zone_domain' => ['https://rankfree.kr', 'bad value', 'rankfree.kr'],
            'cloudflare_zone_id' => ['', 'ignored', 'zone-123'],
            'secondary_domains' => ['https://self.rankfree.kr/path'],
        ])->assertRedirect(route('admin.settings', ['tab' => 'domains']));

        $this->assertSame('cf-token', AppSetting::read('cloudflare.api_token'));
        $this->assertSame('rankfree.kr', AppSetting::read('cloudflare.dns_target'));
        $this->assertSame([['domain' => 'rankfree.kr', 'zone_id' => 'zone-123', 'proxied' => true]], AppSetting::readJson('cloudflare.zones'));
        $this->assertSame(['self.rankfree.kr'], AppSetting::readJson('secondary.domains'));
    }

    public function test_admin_can_create_random_secondary_domains_through_cloudflare(): void
    {
        $super = $this->super();
        AppSetting::write('cloudflare.api_token', 'cf-token');
        AppSetting::write('cloudflare.dns_target', 'rankfree.kr');
        AppSetting::write('cloudflare.zones', json_encode([['domain' => 'rankfree.kr', 'zone_id' => 'zone-123', 'proxied' => true]]));

        Http::fake([
            'https://api.cloudflare.com/client/v4/zones/zone-123/dns_records*' => function ($request) {
                if ($request->method() === 'GET') {
                    return Http::response(['success' => true, 'result' => []], 200);
                }

                return Http::response(['success' => true, 'result' => ['id' => 'dns-record']], 200);
            },
        ]);

        $this->actingAs($super)->post(route('admin.settings.secondary-domain.create'), [
            'zone_domain' => 'rankfree.kr',
            'subdomain' => '',
            'count' => 5,
        ])->assertRedirect(route('admin.settings', ['tab' => 'domains']));

        $domains = AppSetting::readJson('secondary.domains');
        $this->assertCount(5, $domains);
        $this->assertCount(5, array_unique($domains));
        foreach ($domains as $domain) {
            $this->assertMatchesRegularExpression('/^[a-z]+-[a-f0-9]{5}\.rankfree\.kr$/', $domain);
        }

        Http::assertSentCount(10);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer cf-token')
            && $request['type'] === 'CNAME'
            && $request['content'] === 'rankfree.kr'
            && $request['proxied'] === true);
    }

    public function test_ai_keys_save_and_override_services_config(): void
    {
        // 공급자별 고정칸 — ai_key[{provider}]. 알 수 없는 공급자·빈 값은 저장 안 됨.
        $this->actingAs($this->super())->put('/admin/settings', [
            'ai_key' => ['anthropic' => 'sk-ant-123', 'google' => 'gm-456', 'openai' => '', 'bogus' => 'ignored'],
        ])->assertRedirect(route('admin.settings'));

        $saved = AppSetting::readJson('ai.keys');
        $this->assertCount(2, $saved); // openai 빈값·bogus 미지정 제외
        $this->assertSame('anthropic', $saved[0]['provider']);

        $this->reboot();
        $this->assertSame('sk-ant-123', config('services.anthropic.key'));
        $this->assertSame('gm-456', config('services.gemini.key'));
        $this->assertSame('gm-456', \App\Support\GeminiCredentials::apiKey());
        $this->assertSame('gemini-flash-latest', \App\Support\GeminiCredentials::model());
        $this->assertSame(['key' => 'gm-456', 'model' => 'gemini-flash-latest'], \App\Support\GeminiCredentials::credentials());
    }

    public function test_ai_first_key_per_provider_is_primary(): void
    {
        AppSetting::write('ai.keys', json_encode([
            ['provider' => 'anthropic', 'api_key' => 'FIRST'],
            ['provider' => 'anthropic', 'api_key' => 'SECOND'],
        ]));
        $this->reboot();
        $this->assertSame('FIRST', config('services.anthropic.key'));
    }

    /** 키가 없으면 수집 결과(null)를 캐시하지 않아야 함 — 환경 설정에서 키를 넣으면 다음 요청에 즉시 반영. */
    public function test_collector_does_not_cache_when_no_keys(): void
    {
        config(['rankfree.shopping.api_keys' => []]);
        $svc = app(NaverContentVolumeService::class);

        $this->assertNull($svc->counts('여름매트'));
        $cacheKey = 'kw:content:'.md5(mb_strtoupper(str_replace(' ', '', '여름매트')));
        $this->assertFalse(Cache::has($cacheKey), '키 없을 때 결과가 캐시되면 안 됨');
    }

    public function test_keyword_service_rotates_accounts(): void
    {
        // 첫 계정은 인증 실패, 둘째 계정 성공하도록 Http 페이크
        AppSetting::write('searchad.accounts', json_encode([
            ['api_key' => 'BAD', 'customer_id' => '1', 'secret_key' => 'x'],
            ['api_key' => 'GOOD', 'customer_id' => '2', 'secret_key' => 'y'],
        ]));
        $this->reboot();

        \Illuminate\Support\Facades\Http::fake([
            '*/keywordstool*' => function ($request) {
                if ($request->header('X-API-KEY')[0] === 'GOOD') {
                    return \Illuminate\Support\Facades\Http::response(['keywordList' => [
                        ['relKeyword' => '여름매트', 'monthlyPcQcCnt' => 100, 'monthlyMobileQcCnt' => 200, 'compIdx' => '중간'],
                    ]], 200);
                }

                return \Illuminate\Support\Facades\Http::response('', 401);
            },
        ]);

        $r = app(NaverKeywordService::class)->analyze('여름매트');
        $this->assertNotNull($r);
        $this->assertSame(300, $r['monthly_total']); // 둘째(GOOD) 계정으로 성공
    }
}
