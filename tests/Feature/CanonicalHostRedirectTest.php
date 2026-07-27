<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 대표 도메인 통합(2026-07-27) — rankfree.co.kr(+www) → rankfree.kr 301.
 * ⚠️ 서브도메인(어드민 ops-…, 단축 URL sunny-… 등)은 절대 리다이렉트되면 안 된다.
 */
class CanonicalHostRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'rankfree.canonical.host' => 'rankfree.kr',
            'rankfree.canonical.redirect_hosts' => ['rankfree.co.kr', 'www.rankfree.co.kr'],
        ]);
    }

    public function test_apex_co_kr_redirects_to_kr(): void
    {
        $this->get('http://rankfree.co.kr/')
            ->assertStatus(301)
            ->assertHeader('Location', 'https://rankfree.kr/');
    }

    public function test_www_co_kr_redirects(): void
    {
        $this->get('http://www.rankfree.co.kr/')
            ->assertStatus(301)
            ->assertHeader('Location', 'https://rankfree.kr/');
    }

    /** 개별 문서의 링크 자산도 넘어가야 하므로 경로·쿼리를 유지한다. */
    public function test_preserves_path_and_query(): void
    {
        $this->get('http://rankfree.co.kr/keyword/여름이불?utm_source=ai')
            ->assertStatus(301)
            ->assertHeader('Location', 'https://rankfree.kr/keyword/여름이불?utm_source=ai');
    }

    /** ★ 어드민 비밀 호스트는 리다이렉트되면 안 된다(관리자 접속 차단 사고 방지). */
    public function test_admin_subdomain_is_not_redirected(): void
    {
        $res = $this->get('https://ops-388a48cadf.rankfree.co.kr/');
        $this->assertNotSame(301, $res->status());
    }

    /** ★ 단축 URL 서브도메인도 그대로 — 이미 발주로 배포된 주소가 깨지면 안 된다. */
    public function test_short_link_subdomain_is_not_redirected(): void
    {
        $res = $this->get('https://sunny-5f1a8.rankfree.co.kr/');
        $this->assertNotSame(301, $res->status());
    }

    /** 대표 도메인 자신은 통과. */
    public function test_canonical_host_passes_through(): void
    {
        $this->get('https://rankfree.kr/')->assertOk();
    }

    /** 설정이 비어 있으면(로컬 등) 아무 것도 하지 않는다. */
    public function test_disabled_when_not_configured(): void
    {
        config(['rankfree.canonical.host' => '']);

        $this->get('http://rankfree.co.kr/')->assertOk();
    }
}
