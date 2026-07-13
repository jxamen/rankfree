<?php

namespace App\Domain\Place;

use App\Models\SmartplaceAccount;
use Illuminate\Support\Facades\Process;

/**
 * 스마트플레이스 자동 로그인 — 계정별 네이버 아이디/비밀번호로 Playwright 로그인 → 세션 쿠키 발급.
 * node/playwright 경로는 searchadweb 설정을 재사용한다(형제 프로젝트 node_modules).
 * 쿠키는 민감정보 — 반환값을 로그로 남기지 않고 호출자가 즉시 암호화 저장한다.
 */
class SmartplaceLoginService
{
    /**
     * 계정 자격으로 로그인해 세션 쿠키를 발급. $persist=true 면 계정에 암호화 저장.
     * 등록 전 매장 조회처럼 저장 없이 쿠키만 필요할 때는 $persist=false 로 호출.
     *
     * @return array{ok: bool, cookie?: string, reason?: string}
     */
    public function login(SmartplaceAccount $account, bool $persist = true): array
    {
        $id = trim((string) $account->naver_id);
        $pw = (string) $account->naver_pw;
        if ($id === '' || $pw === '') {
            return ['ok' => false, 'reason' => '네이버 아이디/비밀번호가 등록되어 있지 않습니다.'];
        }

        $node = $this->resolveNode();
        $script = base_path('scripts/naver-smartplace-login.cjs');

        // 웹 서버(Apache/php-fpm) 프로세스에는 TEMP/TMP 가 없을 수 있어, Playwright 가 브라우저
        // 프로필용 임시폴더를 만들 때 'undefined\temp' 로 실패한다. 임시경로·SystemRoot 를 명시 주입.
        $tmp = sys_get_temp_dir() ?: base_path('storage/app/tmp');
        if (! is_dir($tmp)) {
            @mkdir($tmp, 0775, true);
        }

        try {
            $res = Process::timeout(180)->env([
                'SP_LOGIN_ID' => $id,
                'SP_LOGIN_PW' => $pw,
                'SP_PLACE_SEQ' => (string) $account->place_seq,
                'SP_LOGIN_UA' => SmartplaceCollector::UA,
                'RANKFREE_PLAYWRIGHT' => (string) config('searchadweb.playwright'),
                'TEMP' => $tmp,
                'TMP' => $tmp,
                'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
            ])->run([$node, $script]);
        } catch (\Throwable $e) {
            // node 실행파일 자체를 못 찾은 경우(웹서버 PATH에 node 없음 등)
            return ['ok' => false, 'reason' => 'node 실행에 실패했습니다 — RANKFREE_NODE 를 node.exe 절대경로로 설정하세요. ('.$e->getMessage().')'];
        }

        $json = $this->lastJsonLine($res->output());
        if (is_array($json) && ! empty($json['ok']) && ! empty($json['cookie'])) {
            // 성공 경로 — 아래로 진행
        } elseif (is_array($json) && isset($json['reason'])) {
            // 스크립트가 사유를 보고함(캡차/자격 등)
            return ['ok' => false, 'reason' => $this->humanReason((string) $json['reason'])];
        } else {
            // stdout 에 JSON 없음 → node/스크립트 실행 자체가 실패. exit code·stderr 로 진단.
            $err = trim($res->errorOutput() !== '' ? $res->errorOutput() : $res->output());
            $diag = $err !== '' ? substr($err, 0, 200) : ('종료코드 '.$res->exitCode());

            return ['ok' => false, 'reason' => '로그인 스크립트가 실행되지 않았습니다 ('.$diag.'). node 경로(RANKFREE_NODE)와 Playwright 설치를 확인하세요.'];
        }

        if ($persist) {
            $account->forceFill([
                'cookie' => (string) $json['cookie'],
                'logged_in_at' => now(),
            ])->save();
        }

        return ['ok' => true, 'cookie' => (string) $json['cookie']];
    }

    /**
     * node 실행 파일 경로 resolve.
     * 웹 서버(php-fpm/Apache) 프로세스의 PATH 에는 node 가 없을 수 있어(CLI 는 되지만 웹은 안 됨),
     * 기본값 'node' 이면 흔한 절대경로를 탐색한다. RANKFREE_NODE 로 명시하면 그 값을 그대로 사용.
     */
    private function resolveNode(): string
    {
        $node = (string) config('searchadweb.node', 'node');
        if ($node !== '' && $node !== 'node') {
            return $node; // 명시 지정(절대경로 등)
        }
        foreach ([
            'C:\\Program Files\\nodejs\\node.exe',
            'C:\\Program Files (x86)\\nodejs\\node.exe',
            '/usr/bin/node', '/usr/local/bin/node', '/opt/homebrew/bin/node',
        ] as $cand) {
            if (is_file($cand)) {
                return $cand;
            }
        }

        return $node;
    }

    /** stdout 마지막 JSON 줄({"ok":...})만 파싱 — Playwright/브라우저 로그와 섞여 나오므로. */
    private function lastJsonLine(string $out): ?array
    {
        foreach (array_reverse(explode("\n", trim($out))) as $l) {
            $l = trim($l);
            if (str_starts_with($l, '{') && str_contains($l, '"ok"')) {
                return json_decode($l, true);
            }
        }

        return null;
    }

    /** 스크립트 사유 코드를 사용자 안내 문구로 변환. */
    private function humanReason(string $reason): string
    {
        return match (true) {
            str_contains($reason, 'playwright_not_found') => '자동 로그인 도구(Playwright)가 설치되어 있지 않습니다. 서버 설정을 확인하세요.',
            str_contains($reason, 'no_credentials') => '네이버 아이디/비밀번호가 등록되어 있지 않습니다.',
            str_contains($reason, 'blocked_or_captcha') => '네이버 로그인이 캡차/2차 인증에 막혔습니다. 잠시 후 다시 시도하거나 해당 계정의 보안 설정을 확인하세요.',
            default => '자동 로그인에 실패했습니다 ('.$reason.').',
        };
    }
}
