<?php

namespace App\Jobs;

use App\Domain\Keyword\NaverDataLabService;
use App\Domain\Shopping\MarketKeywordDataEnricher;
use App\Models\MarketAnalysis;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 시장분석 키워드 데이터 백그라운드 보강(2026-07-23) — 공유 페이지 첫 열람이 검색광고 크롤·
 * 데이터랩 호출을 기다리지 않게, 열람 시점엔 잡만 걸고 여기서 채운다(요일 비율 캐시도 예열).
 * 실패 처리(30분 네거티브 캐시)는 Enricher 가 자체 수행하므로 재시도하지 않는다.
 */
class EnrichMarketKeywordData implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $analysisId) {}

    public function handle(MarketKeywordDataEnricher $enricher, NaverDataLabService $datalab): void
    {
        $a = MarketAnalysis::find($this->analysisId);
        if (! $a) {
            return;
        }

        $enricher->ensure($a);
        if ($a->keyword) {
            $datalab->weekdayRatio($a->keyword);   // 24h 캐시 예열 — 다음 열람부터 즉시 렌더
        }
    }
}
