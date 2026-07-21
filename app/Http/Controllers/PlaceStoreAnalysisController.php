<?php

namespace App\Http\Controllers;

use App\Domain\Place\PlaceSeoAnalyzer;
use App\Domain\Place\RankSlotService;
use App\Models\PlaceStoreAnalysis;
use Illuminate\Http\Request;

class PlaceStoreAnalysisController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $q = trim((string) $request->query('q', ''));

        return view('console.place-store.index', [
            'analyses' => $user->placeStoreAnalyses()
                ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                    ->where('keyword', 'like', '%'.$q.'%')
                    ->orWhere('name', 'like', '%'.$q.'%')
                    ->orWhere('place_id', 'like', '%'.$q.'%')))
                ->latest('updated_at')
                ->limit(100)
                ->get(),
            'q' => $q,
            'usedSlots' => $user->rankSlotsUsedTotal(),
            'maxSlots' => $user->rankSlotLimit(),
        ]);
    }

    public function store(Request $request, RankSlotService $rankSlots, PlaceSeoAnalyzer $analyzer)
    {
        $data = $request->validate([
            'place' => ['required', 'string', 'max:1000'],
            'keyword' => ['required', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $place = $rankSlots->resolvePlace($data['place']);
        $pid = preg_replace('/\D/', '', (string) ($place['place_id'] ?? ''));
        if ($pid === '') {
            return $this->fail($request, '플레이스 URL 또는 ID에서 placeId를 찾지 못했습니다.');
        }

        if (! $user->tryConsumeFeature('place_analysis')) {
            return $this->fail(
                $request,
                '이번 달 플레이스 개별 분석 횟수('.$user->featureLimit('place_analysis').'회)를 모두 사용했습니다. 요금제를 업그레이드하세요.',
                429,
            );
        }

        @set_time_limit(300);

        $keyword = trim($data['keyword']);
        $cat = $place['category'] ?: 'place';
        $score = $analyzer->analyzeOne($keyword, $cat, $pid);
        if (! $score) {
            return $this->fail($request, '분석이 차단되었거나 실패했습니다. nCaptcha 토큰 상태를 확인한 뒤 다시 시도하세요.', 503);
        }

        $name = trim((string) ($score['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($place['place_name'] ?? '')) ?: 'ID '.$pid;
        }

        $analysis = $user->placeStoreAnalyses()->updateOrCreate(
            ['place_id' => $pid, 'keyword' => $keyword],
            [
                'name' => $name,
                'cat' => $cat,
                'rank' => (int) ($score['rnk'] ?? 300),
                'n1' => $score['n1'] ?? null,
                'n2' => $score['n2'] ?? null,
                'n3' => $score['n3'] ?? null,
                'visitor_cnt' => $score['visitor_cnt'] ?? null,
                'blog_cnt' => $score['blog_cnt'] ?? null,
                'save_cnt' => $score['save_cnt'] ?? null,
                'detail' => $this->detailPayload($score, $place, $keyword, $cat),
            ],
        );

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'message' => '개별 분석이 완료되었습니다.',
                'redirect' => route('console.place-store.show', $analysis),
                'share_url' => $analysis->shareUrl(),
            ]);
        }

        return redirect()
            ->route('console.place-store.show', $analysis)
            ->with('status', '개별 분석이 완료되었습니다.');
    }

    public function show(Request $request, PlaceStoreAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        return view('console.place-store.show', [
            'analysis' => $analysis,
            'usedSlots' => $request->user()->rankSlotsUsedTotal(),
            'maxSlots' => $request->user()->rankSlotLimit(),
        ]);
    }

    public function destroy(Request $request, PlaceStoreAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        $analysis->delete();

        return redirect()->route('console.place-store')->with('status', '개별 분석을 삭제했습니다.');
    }

    private function detailPayload(array $score, array $place, string $keyword, string $cat): array
    {
        return [
            'keyword' => $keyword,
            'cat' => $cat,
            'place_url' => $place['place_url'] ?? null,
            'category' => $score['category'] ?? '',
            'tier' => $score['tier'] ?? null,
            'd' => [
                'd1' => $score['d1'] ?? null,
                'd2' => $score['d2'] ?? null,
                'd3' => $score['d3'] ?? null,
                'd4' => $score['d4'] ?? null,
                'd5' => $score['d5'] ?? null,
                'd6' => $score['d6'] ?? null,
                'd7' => $score['d7'] ?? null,
                'd8' => $score['d8'] ?? null,
                'd9' => $score['d9'] ?? null,
                'd10' => $score['d10'] ?? null,
            ],
            'kc' => $score['kc'] ?? null,
            'seo' => $score['seo'] ?? [],
            'benchmark' => $score['benchmark'] ?? null,
            'competitors' => $score['competitors'] ?? [],
            'rep_keywords' => $score['rep_keywords'] ?? [],
            'review_kw' => $score['review_kw'] ?? null,
            'review_quality' => $score['review_quality'] ?? null,
            'analyzed_at' => now()->toIso8601String(),
        ];
    }

    private function fail(Request $request, string $message, int $status = 422)
    {
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['ok' => false, 'message' => $message], $status);
        }

        return back()->withErrors(['place' => $message])->withInput();
    }
}
