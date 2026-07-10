<?php

namespace App\Console\Commands;

use App\Domain\SearchAdWeb\SearchAdWebClient;
use Illuminate\Console\Command;

/** 웹세션 유효성 확인 후 만료 시 재로그인 (스케줄러/워머용). */
class SearchAdWebWarm extends Command
{
    protected $signature = 'searchadweb:warm';

    protected $description = '네이버 검색광고 웹세션 유효성 점검, 만료면 자동 재로그인';

    public function handle(SearchAdWebClient $client): int
    {
        if (! $client->configured()) {
            $this->warn('세션 없음 → 로그인');

            return $this->call('searchadweb:login');
        }
        if ($client->check()) {
            $this->info('세션 유효.');

            return self::SUCCESS;
        }
        $this->warn('세션 만료 → 재로그인');

        return $this->call('searchadweb:login');
    }
}
