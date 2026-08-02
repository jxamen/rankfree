<?php

namespace Tests\Feature;

use App\Models\BlockedIp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * 운영 로그(2026-08-02)에서 실제로 관측된 탐침 경로들.
     * 🔴 하위 경로(/api/.env·/admin/.env)가 루트만큼 두들겨 맞는다 — 패턴 앵커를 ^ 로만 두면 전부 놓친다.
     */
    public static function 관측된_탐침_경로(): array
    {
        return array_map(fn ($p) => [$p], [
            '/.env', '/api/.env', '/admin/.env', '/config/.env', '/backend/.env', '/.env.production',
            '/.git/HEAD', '/.git-credentials', '/.gitlab-ci.yml', '/.github/workflows/deploy.yml',
            '/.aws/credentials', '/.ssh/id_rsa', '/id_rsa', '/id_ed25519', '/.ssh/authorized_keys',
            '/server.key', '/key.pem', '/privatekey.key', '/ssl/localhost.key', '/private-key',
            '/serviceAccountKey.json', '/service-account.json', '/credentials.json', '/secrets.yml',
            '/firebase-adminsdk.json', '/rclone.conf', '/.s3cfg', '/.npmrc', '/.bashrc', '/.zshrc',
            '/.mcp.json', '/.claude.json', '/.docker/config.json', '/.hermes/auth.json', '/.svn/entries',
            // AI 코딩 도구 자격증명을 노린 2차 물결
            '/.claude/settings.json', '/.cursor/mcp.json', '/.codex/config.toml', '/.aider.conf.yml',
            '/.config/anthropic/credentials/default.json', '/.continue/config.json', '/.openclaw/openclaw.json',
            '/.boto', '/terraform.tfstate', '/docker-compose.yaml', '/wp-config.php.bak',
            '/auth.json', '/config.json', '/storage/logs/laravel.log',
        ]);
    }

    #[DataProvider('관측된_탐침_경로')]
    public function test_관측된_탐침_경로는_모두_차단된다(string $path): void
    {
        // 경로마다 다른 IP 로 — 각 경로가 독립적으로 차단을 유발하는지 본다
        $ip = '198.18.'.random_int(0, 255).'.'.random_int(1, 254);

        $this->asIp($ip)->get($path)->assertForbidden();
        $this->assertDatabaseHas('blocked_ips', ['ip' => $ip, 'hit_path' => $path]);
    }

    /** 정상 경로는 절대 걸리면 안 된다. */
    public function test_정상_경로는_차단되지_않는다(): void
    {
        foreach (['/', '/login', '/keywords', '/.well-known/acme-challenge/x'] as $path) {
            $res = $this->asIp('198.51.100.42')->get($path);
            $this->assertNotSame(403, $res->getStatusCode(), "정상 경로가 차단됨: {$path}");
        }

        $this->assertDatabaseCount('blocked_ips', 0);
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
