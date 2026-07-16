<?php

namespace App\Console\Commands;

use App\Domain\Keyword\KeywordHubPublisher;
use App\Models\KeywordSearch;
use Illuminate\Console\Command;

/** 키워드 콘텐츠 허브 — 발행 문서 주기 갱신(refreshed_at 오래된 순 · 주기 미만은 건너뜀). */
class HubRefresh extends Command
{
    protected $signature = 'hub:refresh
        {--limit= : 이번 실행 갱신 상한(기본 config rankfree.hub.refresh_per_run)}';

    protected $description = '키워드 허브 — 발행 문서 스냅샷 재수집(사이트맵 lastmod 갱신 효과)(22)';

    public function handle(KeywordHubPublisher $publisher): int
    {
        $limit = (int) ($this->option('limit') ?: config('rankfree.hub.refresh_per_run', 20));
        $staleBefore = now()->subDays((int) config('rankfree.hub.refresh_after_days', 30));

        $docs = KeywordSearch::where('origin', 'hub')
            ->where(fn ($q) => $q->whereNull('refreshed_at')->orWhere('refreshed_at', '<', $staleBefore))
            ->orderByRaw('refreshed_at is null desc')->orderBy('refreshed_at')
            ->limit($limit)->get();

        if ($docs->isEmpty()) {
            $this->info('갱신 대상 문서가 없습니다.');

            return self::SUCCESS;
        }

        $ok = $skip = 0;
        foreach ($docs as $i => $doc) {
            if ($i > 0) {
                usleep(300_000);
            }
            $publisher->refresh($doc) ? $ok++ : $skip++;
        }
        $this->info("완료 — 갱신 {$ok} · 볼륨없음(스냅샷 유지) {$skip}");

        return self::SUCCESS;
    }
}
