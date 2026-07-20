<?php

namespace App\Http\Controllers;

use App\Domain\Shopping\ShopKeywordExposureAnalyzer;
use App\Models\ShopKeywordAnalysis;
use Illuminate\Http\Request;

/**
 * 쇼핑 노출 키워드 분석(25) — 콘솔 `console.shop-keyword`.
 * 핵심 키워드 + 상품 URL(+ 붙여넣은 쇼핑 필터 HTML) → 키워드 추출·조합 생성 후,
 * 조합의 쇼핑 순위를 배치로 채워가며(폴링) "상위 N위 노출" 키워드를 찾는다.
 */
class ShopKeywordExposureController extends Controller
{
    public function __construct(private ShopKeywordExposureAnalyzer $analyzer) {}

    public function index(Request $request)
    {
        $analyses = ShopKeywordAnalysis::where('user_id', $request->user()->id)
            ->latest()->limit(30)->get();

        return view('console.shop-keyword.index', [
            'analyses' => $analyses,
            'top' => (int) config('rankfree.shopping.exposure.top', 5),
            'defaultCombos' => (int) config('rankfree.shopping.exposure.max_combos', 50),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'core_keyword' => 'required|string|max:120',
            'product' => 'required|string|max:500',
            'filter_html' => 'nullable|string|max:200000',
            'threshold' => 'nullable|integer|min:1|max:40',
            'target_combos' => 'nullable|integer|min:10|max:200',
            'suffixes' => 'nullable|string|max:2000',
        ]);

        $suffixes = array_values(array_filter(array_map('trim',
            preg_split('/[,\r\n]+/u', (string) ($data['suffixes'] ?? '')) ?: []
        )));

        $analysis = $this->analyzer->prepare(
            $request->user(),
            $data['core_keyword'],
            $data['product'],
            $data['filter_html'] ?? null,
            [
                'threshold' => $data['threshold'] ?? null,
                'max_combos' => $data['target_combos'] ?? null,
                'suffixes' => $suffixes,
            ],
        );

        return redirect()->route('console.shop-keyword.show', $analysis);
    }

    /** 배치 순위체크(폴링) — 미확인 조합 일부를 체크하고 진행상황 JSON 반환. */
    public function check(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        $progress = $this->analyzer->checkBatch($analysis);

        return response()->json($progress);
    }

    public function show(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        $items = $analysis->items()->get();
        $tokens = $items->where('kind', 'token')->groupBy('source');
        $combos = $items->where('kind', 'combo')->sortBy(fn ($i) => $this->rankSort($i->rank))->values();

        return view('console.shop-keyword.show', [
            'analysis' => $analysis,
            'tokens' => $tokens,
            'combos' => $combos,
        ]);
    }

    public function destroy(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        $analysis->delete();

        return redirect()->route('console.shop-keyword')->with('status', '분석을 삭제했습니다.');
    }

    /** 정렬용 — 노출(1~) 먼저, 그 다음 순위밖(0), 미확인(null) 순. */
    private function rankSort(?int $rank): int
    {
        return match (true) {
            $rank === null => 1_000_002,
            $rank === -1 => 1_000_001,
            $rank === 0 => 1_000_000,
            default => $rank,
        };
    }
}
