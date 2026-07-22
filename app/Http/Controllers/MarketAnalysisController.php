<?php

namespace App\Http\Controllers;

use App\Domain\Keyword\NaverDataLabService;
use App\Domain\Seo\RelatedDocsService;
use App\Models\MarketAnalysis;
use Illuminate\Http\Request;

/** 쇼핑 시장 분석 내역 — 콘솔 (확장 프로그램 수집분 열람). */
class MarketAnalysisController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        return view('console.market', [
            'analyses' => $request->user()->marketAnalyses()
                ->when($q !== '', fn ($query) => $query->where('keyword', 'like', "%{$q}%"))
                ->latest()->paginate(20)->withQueryString(),
            'q' => $q,
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
    public function shared(string $slug, NaverDataLabService $datalab, RelatedDocsService $related)
    {
        $a = MarketAnalysis::findByShareKey($slug);
        abort_if(! $a, 404);

        // 키워드당 정식 URL 하나(2026-07-22) — 슬러그는 최신 문서가 기본형(-2 없이)으로 인수하므로
        // (HasShareSlug::shareSlugTakesOver) 정식 URL = 슬러그 보유 문서. 파생 슬러그·토큰은 전부 301 통합.
        $canonical = MarketAnalysis::where('keyword', $a->keyword)->whereNotNull('slug')->orderByDesc('id')->first();
        if ($canonical && $canonical->slug && $slug !== $canonical->slug) {
            return redirect()->to(route('market.shared', $canonical->slug), 301);
        }

        // 표시는 그 키워드의 **최신 데이터** — 누가 어떤 문서를 조회했든 같은(가장 신선한) 분석을 보여준다
        $display = MarketAnalysis::where('keyword', $a->keyword)
            ->orderByDesc('updated_at')->orderByDesc('id')->first() ?? $a;

        // 콘솔 상세와 동일하게 요일별 검색 비율(데이터랩 24h 캐시)도 함께 렌더
        $weekday = $display->keyword ? $datalab->weekdayRatio($display->keyword) : null;

        return view('market.share', ['a' => $display, 'weekday' => $weekday, 'related' => $related->sectionsFor($display)]);
    }

    public function destroy(Request $request, MarketAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        $analysis->delete();

        return redirect()->route('console.market')->with('status', "'{$analysis->keyword}' 분석 내역을 삭제했습니다.");
    }
}
