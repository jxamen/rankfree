<?php

namespace Tests\Feature;

use App\Models\BlockedIp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 취약점 탐침 IP 자동 차단(2026-08-02) — .claude/21_SEO_SLUG_SITEMAP.md
 *
 * 사람이 요청할 리 없는 경로(/.env·/.aws/credentials)를 훑는 IP 를 기록·차단하되,
 * 오차단이 서비스를 죽이는 경로(.well-known, 프록시 뒤 공용 IP)는 반드시 살려 둔다.
 */
class ProbeIpBlockTest extends TestCase
{
    use RefreshDatabase;

    private const SCANNER = '203.0.113.77';

    private function asIp(string $ip): self
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    public function test_자격증명_탐침은_차단되고_ip_가_기록된다(): void
    {
        $this->asIp(self::SCANNER)->get('/.env')->assertForbidden();

        $row = BlockedIp::query()->where('ip', self::SCANNER)->first();
        $this->assertNotNull($row);
        $this->assertSame('probe', $row->reason);
        $this->assertSame('/.env', $row->hit_path);
        $this->assertTrue($row->blocked_until->isFuture());
    }

    public function test_차단된_ip_는_정상_경로도_막힌다(): void
    {
        $this->asIp(self::SCANNER)->get('/.aws/credentials')->assertForbidden();

        // 같은 IP 가 홈페이지를 열어도 통과시키지 않는다
        $this->asIp(self::SCANNER)->get('/')->assertForbidden();

        // 다른 IP 는 영향받지 않는다
        $this->asIp('198.51.100.9')->get('/')->assertOk();
    }

    /** 🔴 .well-known 은 점으로 시작해도 정상 경로다 — 막으면 인증서 갱신(ACME)이 실패한다. */
    public function test_well_known_은_탐침으로_보지_않는다(): void
    {
        $this->asIp(self::SCANNER)->get('/.well-known/acme-challenge/abc123')->assertNotFound();

        $this->assertDatabaseCount('blocked_ips', 0);
    }

    public function test_never_block_에_있는_ip_는_차단되지_않는다(): void
    {
        // 기본값에 127.0.0.1 이 들어 있다(로컬·헬스체크)
        $this->asIp('127.0.0.1')->get('/.env')->assertNotFound();

        $this->assertDatabaseCount('blocked_ips', 0);
    }

    /**
     * 🔴 CDN·프록시 뒤인데 TrustProxies 미설정이면 REMOTE_ADDR 은 방문자가 아니라 엣지다.
     * 그 IP 를 차단하면 그 엣지를 지나는 모든 정상 사용자가 함께 막힌다.
     */
    public function test_신뢰하지_않는_프록시_뒤에서는_차단하지_않는다(): void
    {
        $this->asIp(self::SCANNER)
            ->withHeaders(['CF-Connecting-IP' => '1.2.3.4'])
            ->get('/.env')
            ->assertNotFound();

        $this->assertDatabaseCount('blocked_ips', 0);
    }

    public function test_차단이_만료되면_다시_통과한다(): void
    {
        $this->asIp(self::SCANNER)->get('/.env')->assertForbidden();

        BlockedIp::query()->where('ip', self::SCANNER)->update(['blocked_until' => now()->subMinute()]);
        BlockedIp::flushCache();

        $this->asIp(self::SCANNER)->get('/')->assertOk();
    }

    public function test_해제_명령으로_오차단을_되돌릴_수_있다(): void
    {
        $this->asIp(self::SCANNER)->get('/.env')->assertForbidden();

        $this->artisan('security:blocked-ips', ['--unblock' => self::SCANNER])->assertSuccessful();

        $this->assertDatabaseCount('blocked_ips', 0);
        $this->asIp(self::SCANNER)->get('/')->assertOk();
    }

    public function test_만료_기록만_정리한다(): void
    {
        BlockedIp::block('203.0.113.1', '/.env');
        BlockedIp::block('203.0.113.2', '/.git/HEAD');
        BlockedIp::query()->where('ip', '203.0.113.2')->update(['blocked_until' => now()->subHour()]);

        $this->artisan('security:blocked-ips', ['--prune' => true])->assertSuccessful();

        $this->assertDatabaseHas('blocked_ips', ['ip' => '203.0.113.1']);
        $this->assertDatabaseMissing('blocked_ips', ['ip' => '203.0.113.2']);
    }
}
