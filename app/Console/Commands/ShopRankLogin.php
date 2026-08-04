<?php

namespace App\Console\Commands;

use App\Domain\Shopping\ShopSerpBrowserCollector;
use Illuminate\Console\Command;

/**
 * 쇼핑 순위수집용 네이버 자동 로그인 — 브라우저 프로필에 세션을 심는다.
 * 세션이 살아 있으면 스크립트가 스스로 로그인을 생략하므로 크론에 그대로 걸면 된다.
 */
class ShopRankLogin extends Command
{
    protected $signature = 'shoprank:login {--check-only : 로그인하지 않고 현재 세션 상태만 점검} {--manual : 창을 띄워 사람이 직접 로그인(캡차 해결용)}';

    protected $description = '쇼핑 순위수집용 네이버 로그인 세션 확보(브라우저 프로필)';

    public function handle(ShopSerpBrowserCollector $collector): int
    {
        $res = $collector->login((bool) $this->option("check-only"), (bool) $this->option("manual"));

        if (! empty($res['ok'])) {
            $this->info('세션 정상 (쇼핑 검색 status='.($res['shopStatus'] ?? '-').')');

            return self::SUCCESS;
        }

        $reason = (string) ($res['reason'] ?? 'unknown');
        $hint = match ($reason) {
            'no_credentials' => 'NAVER_SHOP_LOGIN_ID / NAVER_SHOP_LOGIN_PW (.env) 를 설정하세요.',
            'blocked_or_captcha' => '네이버가 캡차·추가인증을 요구합니다 — 사람이 한 번 로그인해 풀어야 합니다.',
            'shop_blocked' => '로그인은 됐지만 쇼핑 검색이 막혔습니다(status='.($res['shopStatus'] ?? '-').').',
            'stale' => '세션이 만료됐습니다 — --check-only 없이 실행해 재로그인하세요.',
            default => '',
        };
        $this->error('실패: '.$reason.($hint !== '' ? ' — '.$hint : ''));

        return self::FAILURE;
    }
}
