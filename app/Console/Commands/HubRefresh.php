<?php

namespace App\Console\Commands;

use App\Domain\Keyword\KeywordHubPublisher;
use App\Models\GscStat;
use App\Models\KeywordSearch;
use Illuminate\Console\Command;

/**
 * 키워드 콘텐츠 허브 — 발행 문서 주기 갱신.
 * 우선순위(22 Phase 3): 갱신 주기 지난 문서 중 **GSC 클릭(최근 28일) 많은 순** → 오래된 순.
 * 실제 유입이 있는 문서가 먼저 신선해진다.
 */
class HubRefresh extends Command
{
    protected $signature = 'hub:refresh
        {--limit= : 이번 실행 갱신 상한(기본 config rankfree.hub.refresh_per_run)}';

    protected $description = '키워드 허브 — 발행 문서 스냅샷 재수집(사이트맵 lastmod 갱신 효과)(22)';

    public function handle(KeywordHubPublisher $publisher): int
    {
        $limit = (int) ($this->option('limit') ?: config('rankfree.hub.refresh_per_run', 20));
        $staleBefore = now()->subDays((int) config('rankfree.hub.refresh_after_days', 30));

        $stale = KeywordSearch::where('origin', 'hub')
            ->where(fn ($q) => $q->whereNull('refreshed_at')->orWhere('refreshed_at', '<', $staleBefore))
            ->orderByRaw('refreshed_at is null desc')->orderBy('refreshed_at')
            ->limit(max($limit * 5, 50))->get();

        // GSC 클릭(최근 28일) 우선순위 — 문서 URL(한글 슬러그는 인코딩/원문 둘 다) 클릭 합산
        $urlsOf = fn ($d) => array_unique([url('/keyword/'.rawurlencode((string) $d->slug)), url('/keyword/'.$d->slug)]);
        $clicks = GscStat::where('dimension', 'page')
            ->where('date', '>=', now()->subDays(28)->toDateString())
            ->whereIn('value', $stale->flatMap($urlsOf)->unique()->values())
            ->selectRaw('value, sum(clicks) as c')->groupBy('value')->pluck('c', 'value');
        $docs = $stale
            ->sortByDesc(fn ($d) => array_sum(array_map(fn ($u) => (int) ($clicks[$u] ?? 0), $urlsOf($d))))
            ->take($limit)->values();

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
