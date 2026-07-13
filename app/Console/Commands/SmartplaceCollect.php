<?php

namespace App\Console\Commands;

use App\Domain\Place\SmartplaceCollector;
use App\Domain\Place\SmartplaceLoginService;
use App\Models\SmartplaceAccount;
use Illuminate\Console\Command;
use Throwable;

/**
 * 스마트플레이스 계정 리포트 자동 수집 + 세션 유지 (crm cron/smartplace_collect.php 이식).
 *
 * 각 계정: 저장 쿠키로 통계·리뷰·예약·스마트콜 수집 → 만료면 자동 로그인 후 재수집 → last_result 갱신.
 * 수집(인증 요청) 자체가 네이버 세션을 touch 해 NID 만료를 늦춘다(세션 유지의 핵심).
 * 원본 crm 은 쿠키 없으면 건너뛰고 수동 재시드가 필요했으나, 여기선 아이디/비번이 있으면 자동 재로그인한다.
 */
class SmartplaceCollect extends Command
{
    protected $signature = 'smartplace:collect {--user= : 특정 유저만} {--account= : 특정 계정만}';

    protected $description = '스마트플레이스 계정 통계·리뷰·예약·스마트콜 자동 수집 + 세션 유지';

    public function handle(SmartplaceCollector $collector, SmartplaceLoginService $login): int
    {
        $q = SmartplaceAccount::query();
        if ($this->option('user')) {
            $q->where('user_id', (int) $this->option('user'));
        }
        if ($this->option('account')) {
            $q->where('id', (int) $this->option('account'));
        }
        $accounts = $q->get();
        $this->info($accounts->count().'개 계정 수집 시작');

        $ok = 0;
        $expired = 0;
        $fail = 0;

        foreach ($accounts as $account) {
            $label = $account->label ?: ($account->sp_name ?: ('#'.$account->id));
            try {
                // 1) 저장 쿠키로 우선 수집(빠름 — 매번 로그인하지 않음)
                $result = null;
                if (trim((string) $account->cookie) !== '') {
                    $result = $collector->collect($account->cookie, (string) $account->place_seq, (string) $account->category);
                }

                // 2) 세션 없음/만료 → 아이디/비번 있으면 자동 로그인 후 재수집
                if (! $result || empty($result['loggedIn'])) {
                    if (trim((string) $account->naver_id) === '' || trim((string) $account->naver_pw) === '') {
                        $account->forceFill(['last_status' => 'FAIL'])->save();
                        $fail++;
                        $this->warn("[{$label}] 쿠키 만료 + 아이디/비번 없음 — 건너뜀");

                        continue;
                    }
                    $auth = $login->login($account);
                    if (empty($auth['ok'])) {
                        $account->forceFill(['last_status' => 'FAIL'])->save();
                        $fail++;
                        $this->warn("[{$label}] 자동 로그인 실패 — ".($auth['reason'] ?? ''));

                        continue;
                    }
                    $account->refresh();
                    $result = $collector->collect($account->cookie, (string) $account->place_seq, (string) $account->category);
                }

                if (empty($result['loggedIn'])) {
                    $account->forceFill(['last_status' => 'FAIL'])->save();
                    $expired++;
                    $this->warn("[{$label}] ⚠ 세션 만료(loggedIn=false) → 재로그인/접근권한 확인 필요");

                    continue;
                }

                $account->applyResult($result);
                $ok++;
                $this->line("[{$label}] OK ".($result['name'] ?? ''));
            } catch (Throwable $e) {
                $fail++;
                $this->warn("[{$label}] 예외: ".$e->getMessage());
            }
            sleep(3); // 계정 간 간격(원본 crm 동일 — 네이버 배려)
        }

        $this->info("완료 — 성공 {$ok} / 세션만료 {$expired} / 실패 {$fail}");

        return self::SUCCESS;
    }
}
