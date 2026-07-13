<?php

namespace App\Console\Commands;

use App\Domain\SearchAdWeb\SearchAdWebClient;
use App\Domain\SearchAdWeb\WebSessionStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/** 네이버 검색광고 웹 콘솔 자동 로그인 → 세션 쿠키 저장(암호화). */
class SearchAdWebLogin extends Command
{
    protected $signature = 'searchadweb:login {--if-stale : 세션이 유효하면 로그인 생략(크론 유지용)}';

    protected $description = '네이버 검색광고 웹 콘솔 자동 로그인 후 세션 쿠키를 암호화 저장';

    public function handle(WebSessionStore $store, SearchAdWebClient $client): int
    {
        // 크론 유지: 세션이 살아있으면 Playwright 로그인 생략(저비용 probe)
        if ($this->option('if-stale') && $client->check()) {
            $this->info('세션 유효 — 로그인 생략.');

            return self::SUCCESS;
        }

        $id = (string) config('searchadweb.login.id');
        $pw = (string) config('searchadweb.login.pw');
        if ($id === '' || $pw === '') {
            $this->error('NAVER_ADS_LOGIN_ID / NAVER_ADS_LOGIN_PW (.env) 가 설정되지 않았습니다.');

            return self::FAILURE;
        }

        $node = (string) config('searchadweb.node', 'node');
        $script = base_path('scripts/naver-ads-login.cjs');
        $this->line('Playwright 로그인 시도…');

        $res = Process::timeout(180)->env([
            'NAVER_ADS_LOGIN_ID' => $id,
            'NAVER_ADS_LOGIN_PW' => $pw,
            'NAVER_ADS_WEB_UA' => (string) config('searchadweb.ua'),
            'RANKFREE_PLAYWRIGHT' => (string) config('searchadweb.playwright'),
        ])->run([$node, $script]);

        $out = trim($res->output());
        $line = null;
        foreach (array_reverse(explode("\n", $out)) as $l) {
            $l = trim($l);
            if (str_starts_with($l, '{') && str_contains($l, '"ok"')) {
                $line = $l;
                break;
            }
        }
        $json = $line ? json_decode($line, true) : null;

        if (! is_array($json) || empty($json['ok']) || empty($json['cookie'])) {
            $reason = is_array($json) ? ($json['reason'] ?? '') : substr($out."\n".$res->errorOutput(), 0, 300);
            $this->error('로그인 실패: '.$reason);

            return self::FAILURE;
        }

        $store->save((string) $json['cookie']);
        $this->info('세션 쿠키 저장 완료 — 로그인 성공.');

        return self::SUCCESS;
    }
}
