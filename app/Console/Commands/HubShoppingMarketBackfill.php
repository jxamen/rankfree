<?php

namespace App\Console\Commands;

use App\Domain\Keyword\KeywordHubPublisher;
use App\Models\KeywordSearch;
use App\Models\MarketAnalysis;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 허브 쇼핑 키워드 시장분석 백필(22) — 구 흐름으로 /keyword 문서만 있는 쇼핑 키워드 중
 * 수집 SERP(keyword_shop_ranks)가 있는 것을 시장분석(/market) 문서로 생성한다.
 * 허브 목록의 쇼핑 키워드 클릭 → 시장분석이 열리게 하는 데이터 채움용(링크는 KeywordSearch::publicUrl()).
 */
class HubShoppingMarketBackfill extends Command
{
    protected $signature = 'hub:shopping-market-backfill
        {--limit=0 : 최대 처리 건수(0=전부)}
        {--sleep-ms=300 : 키워드 간 대기(검색광고 API 과호출 방지)}
        {--dry : 대상 집계만 출력}';

    protected $description = '허브 쇼핑 키워드 중 수집 SERP 보유분을 시장분석 문서로 일괄 생성';

    public function handle(KeywordHubPublisher $publisher): int
    {
        $serpKeywords = DB::table('keyword_shop_ranks')->distinct()->pluck('keyword');
        $haveMarket = MarketAnalysis::whereIn('keyword', $serpKeywords)->distinct()->pluck('keyword');

        $targets = KeywordSearch::where('origin', 'hub')
            ->whereHas('category', fn ($c) => $c->where('type', 'shopping'))
            ->whereIn('keyword', $serpKeywords->diff($haveMarket)->values())
            ->with('category:id,name')
            ->orderBy('id')
            ->when((int) $this->option('limit') > 0, fn ($q) => $q->limit((int) $this->option('limit')))
            ->get(['id', 'keyword', 'category_id']);

        $this->info('대상: '.$targets->count().'건 (SERP 보유 '.$serpKeywords->count().' · 시장분석 기보유 '.$haveMarket->count().')');
        if ($this->option('dry') || $targets->isEmpty()) {
            return self::SUCCESS;
        }

        $ok = $skip = $fail = 0;
        $sleepUs = max(0, (int) $this->option('sleep-ms')) * 1000;
        foreach ($targets as $i => $doc) {
            try {
                $made = $publisher->buildShoppingMarketDoc($doc->keyword, $doc->category?->name, $doc->category_id, $reason);
                if ($made) {
                    $ok++;
                    Cache::forget(KeywordSearch::marketSlugCacheKey($doc->keyword));   // 링크 즉시 /market 전환
                } else {
                    $skip++;   // 검색량 부족 등 — 키워드 문서 폴백 유지
                }
            } catch (Throwable $e) {
                $fail++;
                $this->warn("[{$doc->keyword}] ".$e->getMessage());
            }
            if (($i + 1) % 25 === 0) {
                $this->line(($i + 1).'/'.$targets->count()." — 생성 {$ok} · 보류 {$skip} · 오류 {$fail}");
            }
            if ($sleepUs > 0) {
                usleep($sleepUs);
            }
        }

        $this->info("완료 — 생성 {$ok} · 보류 {$skip} · 오류 {$fail}");

        return self::SUCCESS;
    }
}
