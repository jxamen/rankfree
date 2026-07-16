<?php

namespace App\Console\Commands;

use App\Domain\Seo\SearchConsoleService;
use Illuminate\Console\Command;

/** 구글 서치 콘솔 검색 성과 수집 — 매일 크론(스케줄러)·수동 실행. */
class GscCollect extends Command
{
    protected $signature = 'gsc:collect {--days=7 : 수집 기간(일) — 최초 적재는 --days=480 로 최대 16개월}';

    protected $description = '구글 서치 콘솔 검색 성과(클릭·노출·CTR·순위) 수집·적재';

    public function handle(SearchConsoleService $svc): int
    {
        $res = $svc->collect(max(1, (int) $this->option('days')));
        $this->{$res['ok'] ? 'info' : 'error'}($res['message']);

        return $res['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
