<?php

namespace App\Console\Commands;

use App\Domain\Keyword\KeywordHubCollector;
use App\Models\GscStat;
use App\Models\KeywordCandidate;
use App\Models\KeywordCategory;
use App\Models\KeywordSearch;
use Illuminate\Console\Command;

/**
 * 키워드 허브 — GSC 피드백 루프(22 Phase 3): 서치 콘솔 유입 쿼리에서 신규 키워드 후보 발굴.
 * 노출이 이미 발생하는데 허브 문서가 없는 검색어 = 확실한 수요 → 지정 카테고리에 pending 후보로 적재.
 */
class HubDiscover extends Command
{
    protected $signature = 'hub:discover
        {--days=28 : 집계 기간(일)}
        {--min= : 최소 노출수(기본 config rankfree.hub.discover_min_impressions)}
        {--limit=50 : 이번 실행 발굴 상한}';

    protected $description = '키워드 허브 — 서치 콘솔 유입 쿼리에서 신규 키워드 후보 발굴(22 Phase 3)';

    public function handle(): int
    {
        $slug = (string) config('rankfree.hub.discover_category', '');
        if ($slug === '') {
            $this->info('발굴 대상 카테고리 미설정(.env HUB_DISCOVER_CATEGORY=카테고리슬러그) — 건너뜁니다.');

            return self::SUCCESS;
        }
        $cat = KeywordCategory::where('slug', $slug)->first();
        if (! $cat) {
            $this->error("카테고리 '{$slug}' 를 찾을 수 없습니다.");

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $min = (int) ($this->option('min') ?: config('rankfree.hub.discover_min_impressions', 30));

        $rows = GscStat::where('dimension', 'query')
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->selectRaw('value, sum(impressions) as imp, sum(clicks) as clk')
            ->groupBy('value')
            ->havingRaw('sum(impressions) >= ?', [$min])
            ->orderByDesc('imp')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $created = 0;
        $skipped = 0;
        foreach ($rows as $r) {
            $kw = trim((string) preg_replace('/\s+/u', ' ', (string) $r->value));
            if (! KeywordHubCollector::acceptableKeyword($kw)
                || KeywordSearch::where('origin', 'hub')->where('keyword', $kw)->exists()
                || KeywordCandidate::where('keyword', $kw)->exists()) {
                $skipped++;

                continue;
            }
            KeywordCandidate::create([
                'category_id' => $cat->id,
                'keyword' => $kw,
                'source' => 'gsc',
                'status' => 'pending',
                'note' => "GSC 노출 {$r->imp} · 클릭 {$r->clk} (최근 {$days}일)",
            ]);
            $created++;
        }

        $this->info("발굴 완료 — 신규 {$created} · 제외(기존/필터) {$skipped} (기간 {$days}일 · 최소 노출 {$min})");

        return self::SUCCESS;
    }
}
