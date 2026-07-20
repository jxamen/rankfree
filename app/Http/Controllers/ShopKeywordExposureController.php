<?php

namespace App\Http\Controllers;

use App\Domain\Shopping\ShopKeywordExposureAnalyzer;
use App\Models\ShopKeywordAnalysis;
use App\Models\ShopKeywordAnalysisItem;
use Illuminate\Http\Request;

/**
 * 쇼핑 노출 키워드 분석(25) — 콘솔 `console.shop-keyword`.
 * 핵심 키워드 + 상품 URL → 키워드 자동 추출·조합 생성 후, 모바일 검색 가격비교 순위를 배치로
 * 채워가며(폴링) "상위 N위 노출" 키워드를 찾는다. 추출 키워드·조합은 개별 삭제 가능.
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
            'defaultCombos' => (int) config('rankfree.shopping.exposure.max_combos', 100),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'core_keyword' => 'required|string|max:120',
            'product' => 'required|string|max:500',
            'threshold' => 'nullable|integer|min:1|max:40',
            'target_combos' => 'nullable|integer|min:10|max:500',
        ]);

        $analysis = $this->analyzer->prepare(
            $request->user(),
            $data['core_keyword'],
            $data['product'],
            null,
            ['threshold' => $data['threshold'] ?? null, 'max_combos' => $data['target_combos'] ?? null],
        );

        return redirect()->route('console.shop-keyword.show', $analysis);
    }

    /** 배치 순위체크(폴링) — 미확인 조합 일부를 체크하고 진행상황 JSON 반환. */
    public function check(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        return response()->json($this->analyzer->checkBatch($analysis));
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

    /** 추출 키워드/조합 개별 삭제 — 추출 키워드 삭제 시 그 단어를 포함한 조합도 함께 제거(조합 자동 변경). */
    public function deleteItem(Request $request, ShopKeywordAnalysis $analysis, ShopKeywordAnalysisItem $item)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        abort_unless($item->analysis_id === $analysis->id, 404);

        $removedCombos = 0;
        if ($item->kind === 'token') {
            $removedCombos = $analysis->combos()
                ->where('keyword', 'like', '%'.$this->escapeLike($item->keyword).'%')->delete();
        }
        $item->delete();

        $th = (int) $analysis->threshold;
        $analysis->update([
            'token_count' => $analysis->tokens()->count(),
            'combo_count' => $analysis->combos()->count(),
            'checked_count' => $analysis->combos()->whereNotNull('rank')->count(),
            'exposed_count' => $analysis->combos()->whereBetween('rank', [1, $th])->count(),
        ]);

        return response()->json(['ok' => true, 'removed_combos' => $removedCombos]);
    }

    public function destroy(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        $analysis->delete();

        return redirect()->route('console.shop-keyword')->with('status', '분석을 삭제했습니다.');
    }

    private function escapeLike(string $s): string
    {
        return addcslashes($s, '\\%_');
    }

    /** 정렬용 — 노출(1~) 먼저, 그 다음 순위밖(0), 미확인(null) 순. */
    private function rankSort(?int $rank): int
    {
        return match (true) {
            $rank === null => 1_000_002,
            $rank <= 0 => 1_000_000,
            default => $rank,
        };
    }
}
