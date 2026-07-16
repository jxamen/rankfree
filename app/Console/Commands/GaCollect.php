<?php

namespace App\Console\Commands;

use App\Domain\Seo\GoogleAnalyticsService;
use Illuminate\Console\Command;

/** GA4 방문 통계 수집 — 매일 크론(스케줄러)·수동 실행. */
class GaCollect extends Command
{
    protected $signature = 'ga:collect {--days=7 : 수집 기간(일) — 최초 적재는 --days=400 등 크게}';

    protected $description = 'GA4 방문 통계(사용자·세션·페이지뷰·채널·소스) 수집·적재';

    public function handle(GoogleAnalyticsService $svc): int
    {
        $res = $svc->collect(max(1, (int) $this->option('days')));
        $this->{$res['ok'] ? 'info' : 'error'}($res['message']);

        return $res['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
