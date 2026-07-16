<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Seo\GoogleAnalyticsService;
use App\Http\Controllers\Controller;
use App\Models\GaStat;
use Illuminate\Http\Request;

/** 방문 분석(admin) — GA4 방문 통계(사용자·세션·페이지뷰·채널·소스) 대시보드. */
class TrafficStatsController extends Controller
{
    public function index(Request $request)
    {
        $days = in_array((int) $request->query('days'), [7, 28, 90], true) ? (int) $request->query('days') : 28;
        $from = now('Asia/Seoul')->subDays($days)->toDateString();

        $daily = GaStat::where('dimension', 'date')->where('date', '>=', $from)->orderBy('date')->get();

        $agg = function (string $dim, int $limit, string $metric = 'sessions') use ($from) {
            return GaStat::where('dimension', $dim)->where('date', '>=', $from)
                ->selectRaw('value, sum(users) as users, sum(sessions) as sessions, sum(pageviews) as pageviews')
                ->groupBy('value')
                ->orderByDesc($metric)
                ->limit($limit)->get();
        };

        return view('admin.traffic-stats.index', [
            'days' => $days,
            'daily' => $daily,
            'totals' => [
                'users' => (int) $daily->sum('users'),
                'new_users' => (int) $daily->sum('new_users'),
                'sessions' => (int) $daily->sum('sessions'),
                'pageviews' => (int) $daily->sum('pageviews'),
            ],
            'channels' => $agg('channel', 10),
            'sources' => $agg('source', 30),
            'pages' => $agg('page', 30, 'pageviews'),
            'configured' => GoogleAnalyticsService::configured(),
            'serviceEmail' => \App\Support\GoogleServiceAccount::clientEmail(),
            'propertyId' => GoogleAnalyticsService::propertyId(),
            'lastCollectedAt' => GaStat::max('updated_at'),
        ]);
    }

    /** 지금 수집 — 최근 7일 갱신. */
    public function collect(GoogleAnalyticsService $svc)
    {
        $res = $svc->collect(7);

        return back()->with($res['ok'] ? 'status' : 'error_status', $res['message'])
            ->withErrors($res['ok'] ? [] : ['collect' => $res['message']]);
    }
}
