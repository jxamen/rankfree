<?php

namespace App\Console\Commands;

use App\Domain\Place\NcaptchaTokenStore;
use Illuminate\Console\Command;

/**
 * nCaptcha 토큰 저장 — 로컬 발급 도구(scripts/ncaptcha-token.cjs)가 호출.
 * 사용: php artisan place:set-token "<token>"
 * 상태: php artisan place:set-token --status
 */
class SetNcaptchaToken extends Command
{
    protected $signature = 'place:set-token {token? : nCaptcha 토큰} {--status : 현재 토큰 상태만 표시}';

    protected $description = '플레이스 순위체크용 nCaptcha 토큰 저장/조회';

    public function handle(): int
    {
        if ($this->option('status')) {
            $has = NcaptchaTokenStore::has();
            $this->line($has
                ? '토큰 있음 (갱신: ' . (NcaptchaTokenStore::updatedAt() ?? '알 수 없음') . ')'
                : '토큰 없음 — scripts/ncaptcha-token.cjs 를 실행해 발급하세요.');

            return self::SUCCESS;
        }

        $token = (string) $this->argument('token');
        if (trim($token) === '') {
            $this->error('토큰이 비어 있습니다. 사용법: php artisan place:set-token "<token>"');

            return self::FAILURE;
        }

        NcaptchaTokenStore::save($token);
        $this->info('nCaptcha 토큰 저장 완료 (len=' . strlen($token) . ')');

        return self::SUCCESS;
    }
}
