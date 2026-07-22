<?php

namespace Jcurve\Ga4Insights\Http;

use Illuminate\Http\Request;
use Jcurve\Ga4Insights\Ga4Reporter;

/** GA4 상세 분석 대시보드 — 라우트 마운트/레이아웃은 config('ga4-insights.*'). */
class Ga4DashboardController
{
    /** 'today'=오늘(당일 집계 중) · 1=어제 하루 · 나머지는 어제까지 최근 N일. */
    private const PRESETS = ['today', 1, 7, 14, 28, 90];

    public function index(Request $request, Ga4Reporter $reporter)
    {
        $period = $this->period($request->query('days'));

        $ga = ['days' => $period, 'presets' => self::PRESETS, 'configured' => $reporter->isConfigured()];

        if ($ga['configured']) {
            $ga += $reporter->report($period);
        }

        return view('ga4-insights::dashboard', ['ga' => $ga]);
    }

    /** 캐시 비우고 최신 GA4 데이터 다시 조회. */
    public function refresh(Request $request, Ga4Reporter $reporter)
    {
        $period = $this->period($request->input('days'));
        if ($reporter->isConfigured()) {
            $reporter->flush($period);
        }

        return redirect()->to(url()->previous() ?: '/');
    }

    private function period(mixed $raw): int|string
    {
        if ($raw === 'today') {
            return 'today';
        }

        return in_array((int) $raw, self::PRESETS, true) ? (int) $raw : 28;
    }
}
