<?php

namespace App\Support;

use App\Models\GscStat;
use Jcurve\Ga4Insights\Contracts\KeywordsProvider;

/**
 * GA4 대시보드 '검색 유입 키워드' — 구글 서치 콘솔 수집분(gsc_stats, 20번)에서 기간 상위 검색어.
 * GSC 원천이 2~3일 지연이라 최근 며칠(특히 '오늘')은 비어 있는 게 정상.
 */
class Ga4GscKeywordsProvider implements KeywordsProvider
{
    public function rows(string $startDate, string $endDate, int $limit = 15): array
    {
        return GscStat::query()
            ->where('dimension', 'query')
            ->whereBetween('date', [$startDate, $endDate])
            ->selectRaw('value, SUM(clicks) c, SUM(impressions) i, SUM(position * impressions) pw')
            ->groupBy('value')
            ->orderByDesc('c')->orderByDesc('i')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn ($r) => [
                'query' => (string) $r->value,
                'clicks' => (int) $r->c,
                'impressions' => (int) $r->i,
                'position' => (float) $r->i > 0 ? round((float) $r->pw / (float) $r->i, 1) : null,
            ])->all();
    }
}
