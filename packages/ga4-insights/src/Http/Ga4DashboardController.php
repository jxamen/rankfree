<?php

namespace Jcurve\Ga4Insights\Http;

use Illuminate\Http\Request;
use Jcurve\Ga4Insights\Ga4Reporter;

/** GA4 상세 분석 대시보드 — 라우트 마운트/레이아웃은 config('ga4-insights.*'). */
class Ga4DashboardController
{
    private const PRESETS = [7, 14, 28, 90];

    public function index(Request $request, Ga4Reporter $reporter)
    {
        $days = in_array((int) $request->query('days'), self::PRESETS, true) ? (int) $request->query('days') : 28;

        $ga = ['days' => $days, 'presets' => self::PRESETS, 'configured' => $reporter->isConfigured()];

        if ($ga['configured']) {
            $ga += $reporter->report($days);
        }

        return view('ga4-insights::dashboard', ['ga' => $ga]);
    }

    /** 캐시 비우고 최신 GA4 데이터 다시 조회. */
    public function refresh(Request $request, Ga4Reporter $reporter)
    {
        $days = in_array((int) $request->input('days'), self::PRESETS, true) ? (int) $request->input('days') : 28;
        if ($reporter->isConfigured()) {
            $reporter->flush($days);
        }

        return redirect()->to(url()->previous() ?: '/');
    }
}
