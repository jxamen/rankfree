<?php

namespace App\Console\Commands;

use App\Domain\Seo\SearchEnginePing;
use Illuminate\Console\Command;

/**
 * 검색엔진 알림 수동 실행(21) — 구글 서치 콘솔 사이트맵 재제출 +(--url 지정 시) IndexNow 제출.
 * 발행 훅과 별개로 최초 등록·점검 때 손으로 돌려볼 수 있게 둔다.
 */
class SeoPing extends Command
{
    protected $signature = 'seo:ping
        {--url=* : IndexNow(네이버·빙)로 보낼 페이지 URL — 생략하면 구글 사이트맵 재제출만}';

    protected $description = '검색엔진 알림 — 구글 사이트맵 재제출 + IndexNow URL 제출(21_SEO_SLUG_SITEMAP)';

    public function handle(SearchEnginePing $ping): int
    {
        if (! SearchEnginePing::enabled()) {
            $this->warn('SEO_PING_ENABLED=false — 비활성 상태입니다.');

            return self::SUCCESS;
        }

        if ($urls = (array) $this->option('url')) {
            $in = $ping->pingIndexNow($urls);
            $this->line('IndexNow(네이버·빙): '.$in['message']);
        }

        $gsc = $ping->submitSitemapToGoogle();
        $this->line('구글 사이트맵: '.$gsc['message']);

        return self::SUCCESS;
    }
}
