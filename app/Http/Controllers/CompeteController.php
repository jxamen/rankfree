<?php

namespace App\Http\Controllers;

use App\Domain\Place\PlaceScorer;
use App\Domain\Place\PlaceSeoAnalyzer;
use App\Models\PlaceRankSlot;
use App\Models\PlaceSeoDaily;
use App\Models\PlaceSeoScore;
use App\Models\PlaceSeoSerp;
use Illuminate\Http\Request;

/** 플레이스 경쟁 분석(SEO 점수 + 순위추적) — 웹 콘솔. 로직은 PlaceSeoAnalyzer 공유. */
class CompeteController extends Controller
{
    /** 트랙(=순위추적 슬롯) 목록 + 최신 N1/N2/N3. */
    public function index(Request $request)
    {
        $user = $request->user();
        $slots = $user->rankSlots()->latest()->get();

        $latest = PlaceSeoScore::whereIn('slot_id', $slots->pluck('id'))
            ->where('is_mine', true)
            ->orderByDesc('ymd')
            ->get()
            ->groupBy('slot_id')
            ->map(fn ($g) => $g->first());

        return view('console.compete.index', [
            'slots' => $slots,
            'latest' => $latest,
            'usedSlots' => $user->rankSlotsUsed(),
            'maxSlots' => $user->rankSlotLimit(),
        ]);
    }

    /** 분석 실행(경쟁셋 수집 + 점수 저장). */
    public function analyze(Request $request, PlaceRankSlot $slot, PlaceSeoAnalyzer $analyzer)
    {
        abort_unless($slot->user_id === $request->user()->id, 403);
        @set_time_limit(300); // 경쟁셋 상세 + 리뷰 수집은 수십 초 소요(동기)

        $res = $analyzer->analyze($slot, 10);
        if ($res['blocked']) {
            return back()->with('status', '조회가 일시적으로 제한됐습니다 (nCaptcha 토큰 재발급 필요).');
        }

        return redirect()->route('console.compete.show', $slot)
            ->with('status', "분석 완료 · 내 순위 {$res['my_rank']}위 · 경쟁 {$res['competitors']}개");
    }

    /** 경쟁 비교 상세. */
    public function show(Request $request, PlaceRankSlot $slot)
    {
        abort_unless($slot->user_id === $request->user()->id, 403);

        $ymd = PlaceSeoScore::where('slot_id', $slot->id)->max('ymd');
        $data = $ymd ? $this->buildComparison($slot, $ymd) : ['rows' => collect(), 'mine' => null, 'explain' => null];

        $series = $ymd
            ? PlaceSeoScore::where('slot_id', $slot->id)->where('place_id', $slot->place_id)
                ->orderBy('ymd')->get(['ymd', 'n1', 'n2', 'n3', 'rnk'])
            : collect();

        return view('console.compete.show', [
            'slot' => $slot,
            'ymd' => $ymd,
            'rows' => $data['rows'],
            'mine' => $data['mine'],
            'explain' => $data['explain'],
            'series' => $series,
        ]);
    }

    /** slot+ymd → 비교 행 + 내 매장 explain. (API 와 공유 가능한 순수 조립) */
    public function buildComparison(PlaceRankSlot $slot, string $ymd): array
    {
        $scores = PlaceSeoScore::where('slot_id', $slot->id)->where('ymd', $ymd)->get()->keyBy('place_id');
        $dailies = PlaceSeoDaily::where('ymd', $ymd)->whereIn('place_id', $scores->keys())->get()->keyBy('place_id');

        $rows = PlaceSeoSerp::where('slot_id', $slot->id)->where('ymd', $ymd)
            ->orderByDesc('is_mine')->orderBy('rnk')->get()
            ->map(function ($s) use ($scores) {
                $sc = $scores[$s->place_id] ?? null;

                return (object) [
                    'rnk' => $s->rnk, 'name' => $s->name, 'place_id' => $s->place_id, 'is_mine' => $s->is_mine,
                    'address' => $s->address, 'visitor' => $s->visitor_cnt, 'blog' => $s->blog_cnt, 'score' => $s->review_score,
                    'd7' => $sc?->d7, 'n1' => $sc?->n1, 'n2' => $sc?->n2, 'n3' => $sc?->n3, 'tier' => $sc?->tier,
                ];
            });

        $mine = $rows->firstWhere('is_mine', true);

        $explain = null;
        $mineDaily = $dailies[$slot->place_id] ?? null;
        $mineScore = $scores[$slot->place_id] ?? null;
        if ($mine && $mineDaily) {
            $cat = $slot->category ?: 'place';
            $explain = [
                'components' => PlaceScorer::keywordComponents($slot->keyword, $mineDaily->name, (string) $mineDaily->category, (string) $mine->address, $mineDaily->tags ?? [], $cat),
                'seo' => PlaceScorer::seoItems($mineDaily->toArray(), $cat),
                'dims' => $mineScore,
                'daily' => $mineDaily,
            ];
        }

        return ['rows' => $rows, 'mine' => $mine, 'explain' => $explain];
    }
}
