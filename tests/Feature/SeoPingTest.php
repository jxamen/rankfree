<?php

namespace Tests\Feature;

use App\Console\Commands\SitemapRefresh;
use App\Domain\Seo\SearchEnginePing;
use App\Models\AppSetting;
use App\Models\KeywordCategory;
use App\Models\KeywordSearch;
use App\Support\GoogleToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 검색엔진 발행 알림(21) — IndexNow 키 파일 서빙·URL 제출, 구글 사이트맵 재제출,
 * GoogleToken 스코프 정확 매칭(readonly 연동이 쓰기 요청에 오탑승하지 않게).
 */
class SeoPingTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'abcdef0123456789abcdef0123456789';

    // ── IndexNow 키 파일 라우트 ─────────────────────────────────────────

    public function test_indexnow_key_file_is_served(): void
    {
        config(['seo-ping.indexnow.key' => self::KEY]);

        $res = $this->get('/'.self::KEY.'.txt');

        $res->assertOk();
        $this->assertSame(self::KEY, $res->getContent());
        $this->assertStringStartsWith('text/plain', (string) $res->headers->get('Content-Type'));
    }

    public function test_indexnow_key_file_404_on_mismatch_or_unset(): void
    {
        config(['seo-ping.indexnow.key' => self::KEY]);
        $this->get('/00000000000000000000000000000000.txt')->assertNotFound();

        config(['seo-ping.indexnow.key' => '']);
        $this->get('/'.self::KEY.'.txt')->assertNotFound();
    }

    // ── IndexNow 제출 ──────────────────────────────────────────────────

    public function test_ping_indexnow_submits_encoded_urls(): void
    {
        config(['seo-ping.indexnow.key' => self::KEY]);
        Http::fake(['api.indexnow.org/*' => Http::response('', 200)]);

        $out = app(SearchEnginePing::class)->pingIndexNow(['http://localhost/keyword/캠핑의자']);

        $this->assertTrue($out['ok']);
        Http::assertSent(function ($req) {
            return str_contains($req->url(), 'api.indexnow.org')
                && $req['key'] === self::KEY
                && str_contains($req['keyLocation'], self::KEY.'.txt')
                && in_array('http://localhost/keyword/%EC%BA%A0%ED%95%91%EC%9D%98%EC%9E%90', $req['urlList'], true);
        });
    }

    public function test_ping_indexnow_skips_without_key(): void
    {
        config(['seo-ping.indexnow.key' => '']);
        Http::fake();

        $out = app(SearchEnginePing::class)->pingIndexNow(['http://localhost/keywords']);

        $this->assertFalse($out['ok']);
        $this->assertStringContainsString('건너뜀', $out['message']);
        Http::assertNothingSent();
    }

    // ── 구글 사이트맵 재제출 ────────────────────────────────────────────

    public function test_gsc_submit_skips_without_credentials(): void
    {
        Http::fake();

        $out = app(SearchEnginePing::class)->submitSitemapToGoogle();

        $this->assertFalse($out['ok']);
        $this->assertStringContainsString('건너뜀', $out['message']);
        Http::assertNothingSent();
    }

    public function test_gsc_submit_puts_sitemap_with_write_scope_oauth(): void
    {
        AppSetting::write(GoogleToken::KEY_REFRESH, 'refresh-token');
        AppSetting::write(GoogleToken::KEY_SCOPES, 'openid email https://www.googleapis.com/auth/webmasters');
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at-1'], 200),
            'searchconsole.googleapis.com/*' => Http::response('', 200),
        ]);

        $out = app(SearchEnginePing::class)->submitSitemapToGoogle();

        $this->assertTrue($out['ok']);
        Http::assertSent(fn ($req) => $req->method() === 'PUT'
            && str_contains($req->url(), 'searchconsole.googleapis.com/webmasters/v3/sites/')
            && str_contains($req->url(), '/sitemaps/'.rawurlencode(route('sitemap'))));
    }

    // ── GoogleToken 스코프 정확 매칭 ────────────────────────────────────

    public function test_readonly_grant_does_not_satisfy_write_scope(): void
    {
        AppSetting::write(GoogleToken::KEY_REFRESH, 'refresh-token');
        AppSetting::write(GoogleToken::KEY_SCOPES, 'https://www.googleapis.com/auth/webmasters.readonly');
        Http::fake();

        // readonly 연동은 쓰기 스코프를 못 채운다 → OAuth 미사용, 서비스 계정도 없으니 null
        $this->assertNull(GoogleToken::token('https://www.googleapis.com/auth/webmasters'));
        Http::assertNothingSent();
    }

    public function test_full_grant_satisfies_readonly_scope(): void
    {
        AppSetting::write(GoogleToken::KEY_REFRESH, 'refresh-token');
        AppSetting::write(GoogleToken::KEY_SCOPES, 'https://www.googleapis.com/auth/webmasters');
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'at-2'], 200)]);

        $this->assertSame('at-2', GoogleToken::token('https://www.googleapis.com/auth/webmasters.readonly'));
    }

    // ── 발행 훅 통합 ───────────────────────────────────────────────────

    public function test_after_hub_publish_pings_doc_and_hub_urls_and_bumps_sitemap(): void
    {
        config(['seo-ping.indexnow.key' => self::KEY]);
        Http::fake(['api.indexnow.org/*' => Http::response('', 202)]);

        $cat = KeywordCategory::create([
            'type' => 'shopping', 'name' => '캠핑용품', 'slug' => '캠핑용품',
            'seed_keywords' => ['캠핑의자'], 'is_active' => true,
        ]);
        $doc = KeywordSearch::create(['origin' => 'hub', 'keyword' => '캠핑의자', 'category_id' => $cat->id]);

        $before = SitemapRefresh::version();
        $msg = app(SearchEnginePing::class)->afterHubPublish(collect([$doc]));

        $this->assertStringContainsString('IndexNow', $msg);
        $this->assertGreaterThan($before, SitemapRefresh::version());
        Http::assertSent(function ($req) use ($doc) {
            if (! str_contains($req->url(), 'api.indexnow.org')) {
                return false;
            }
            $urls = $req['urlList'];
            $encodedDoc = 'http://localhost/keyword/'.rawurlencode($doc->slug);

            return in_array($encodedDoc, $urls, true)
                && in_array(route('keywords.index'), $urls, true)
                && in_array('http://localhost/keywords/'.rawurlencode('캠핑용품'), $urls, true);
        });
    }

    public function test_after_hub_publish_disabled_returns_empty(): void
    {
        config(['seo-ping.enabled' => false]);
        Http::fake();

        $msg = app(SearchEnginePing::class)->afterHubPublish(collect([
            new KeywordSearch(['origin' => 'hub', 'keyword' => 'x']),
        ]));

        $this->assertSame('', $msg);
        Http::assertNothingSent();
    }
}
