<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Seo\SearchConsoleService;
use App\Http\Controllers\Controller;
use App\Models\GscStat;
use Illuminate\Http\Request;

/** 검색 유입 분석(admin) — 구글 서치 콘솔 성과(클릭·노출·CTR·순위) 대시보드. */
class SearchStatsController extends Controller
{
    public function index(Request $request)
    {
        $days = in_array((int) $request->query('days'), [7, 28, 90], true) ? (int) $request->query('days') : 28;
        $from = now('Asia/Seoul')->subDays($days + 2)->toDateString();   // GSC 3일 지연 감안

        $daily = GscStat::where('dimension', 'date')->where('date', '>=', $from)->orderBy('date')->get();

        $agg = function (string $dim, int $limit) use ($from) {
            return GscStat::where('dimension', $dim)->where('date', '>=', $from)
                ->selectRaw('value, sum(clicks) as clicks, sum(impressions) as impressions, sum(position * impressions) as pos_w')
                ->groupBy('value')
                ->orderByDesc('clicks')->orderByDesc('impressions')
                ->limit($limit)->get()
                ->map(function ($r) {
                    $r->ctr = $r->impressions > 0 ? $r->clicks / $r->impressions : 0;
                    $r->position = $r->impressions > 0 ? $r->pos_w / $r->impressions : 0;

                    return $r;
                });
        };

        $totImp = (int) $daily->sum('impressions');

        return view('admin.search-stats.index', [
            'days' => $days,
            'daily' => $daily,
            'totals' => [
                'clicks' => (int) $daily->sum('clicks'),
                'impressions' => $totImp,
                'ctr' => $totImp > 0 ? $daily->sum('clicks') / $totImp : 0,
                'position' => $totImp > 0 ? $daily->sum(fn ($d) => $d->position * $d->impressions) / $totImp : 0,
            ],
            'topQueries' => $agg('query', 50),
            'topPages' => $agg('page', 30),
            'devices' => $agg('device', 5),
            'configured' => SearchConsoleService::configured(),
            'serviceEmail' => \App\Support\GoogleServiceAccount::clientEmail(),
            'property' => SearchConsoleService::property(),
            'lastCollectedAt' => GscStat::max('updated_at'),
        ]);
    }

    /** 지금 수집 — 최근 7일 갱신. */
    public function collect(SearchConsoleService $svc)
    {
        $res = $svc->collect(7);

        return back()->with($res['ok'] ? 'status' : 'error_status', $res['message'])
            ->withErrors($res['ok'] ? [] : ['collect' => $res['message']]);
    }
}
