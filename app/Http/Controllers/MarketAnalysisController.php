<?php

namespace App\Http\Controllers;

use App\Domain\Keyword\NaverDataLabService;
use App\Models\MarketAnalysis;
use Illuminate\Http\Request;

/** 쇼핑 시장 분석 내역 — 콘솔 (확장 프로그램 수집분 열람). */
class MarketAnalysisController extends Controller
{
    public function index(Request $request)
    {
        return view('console.market', [
            'analyses' => $request->user()->marketAnalyses()->latest()->paginate(20),
        ]);
    }

    public function show(Request $request, MarketAnalysis $analysis, NaverDataLabService $datalab)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        // 요일별 검색 비율(데이터랩) — 키워드 분석과 동일 지표(공유 모듈에서 렌더)
        $weekday = $analysis->keyword ? $datalab->weekdayRatio($analysis->keyword) : null;

        return view('console.market-show', ['a' => $analysis, 'weekday' => $weekday]);
    }

    /** 공개 공유 리포트 — 공유 토큰으로 비로그인 열람. */
    public function shared(string $token, NaverDataLabService $datalab)
    {
        $a = MarketAnalysis::where('share_token', $token)->firstOrFail();

        // 콘솔 상세와 동일하게 요일별 검색 비율(데이터랩 24h 캐시)도 함께 렌더
        $weekday = $a->keyword ? $datalab->weekdayRatio($a->keyword) : null;

        return view('market.share', ['a' => $a, 'weekday' => $weekday]);
    }

    public function destroy(Request $request, MarketAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        $analysis->delete();

        return redirect()->route('console.market')->with('status', "'{$analysis->keyword}' 분석 내역을 삭제했습니다.");
    }
}
