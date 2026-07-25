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
    public function shared(Request $request, string $slug, NaverDataLabService $datalab, RelatedDocsService $related)
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

        // keyword_data('키워드 분석' 섹션) 보강. 발행분은 발행 시점에 이미 채워지지만(KeywordHubPublisher),
        // 미완비 문서(1회성·완비 실패분)를 **크롤러(검색엔진·AI)가 첫 방문**에 만나면 빈 thin 문서로
        // 색인되지 않게 그 자리에서 동기로 채운다. **사람은 기존대로 잡만 예약**(첫 로드 빠르게 — 2026-07-23 성능 사고 회피).
        $enricher = app(\App\Domain\Shopping\MarketKeywordDataEnricher::class);
        if ($enricher->needs($display) && $this->isCrawler($request)) {
            try {
                $enricher->ensure($display);   // 봇만 대기 — 완비된 HTML 을 색인시킨다
            } catch (\Throwable $e) {
                // 크롤 실패 시 기존처럼 빈 섹션 렌더(30분 네거티브 캐시로 반복 크롤 방지)
            }
        } else {
            $enricher->ensureAsync($display);
        }

        // 콘솔 상세와 동일하게 요일별 검색 비율(데이터랩 24h 캐시)도 함께 렌더
        $weekday = $display->keyword ? $datalab->weekdayRatio($display->keyword) : null;

        return view('market.share', ['a' => $display, 'weekday' => $weekday, 'related' => $related->sectionsFor($display)]);
    }

    /** 검색엔진·AI 크롤러 User-Agent 감지 — 이들에겐 keyword_data 를 동기로 완비해 빈 색인을 막는다. */
    private function isCrawler(Request $request): bool
    {
        $ua = (string) $request->userAgent();

        return $ua !== '' && preg_match(
            '/googlebot|bingbot|petalbot|yeti|daumoa|applebot|perplexitybot|perplexity-user|gptbot|oai-searchbot|chatgpt-user|claudebot|anthropic|google-extended|ccbot|bytespider|slurp|duckduckbot|facebookexternalhit|twitterbot|linkedinbot/i',
            $ua
        ) === 1;
    }

    public function destroy(Request $request, MarketAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        $analysis->delete();

        return redirect()->route('console.market')->with('status', "'{$analysis->keyword}' 분석 내역을 삭제했습니다.");
    }
}
