<?php

namespace App\Http\Controllers\Api;

use App\Domain\Place\PlaceScorer;
use App\Domain\Place\PlaceSeoAnalyzer;
use App\Http\Controllers\Controller;
use App\Models\PlaceRankSlot;
use App\Models\PlaceSeoDaily;
use App\Models\PlaceSeoScore;
use App\Models\PlaceSeoSerp;
use Illuminate\Http\Request;

/**
 * 플레이스 경쟁 분석 API (auth.ext Bearer). 웹 콘솔과 동일 로직 공유.
 * 확장/외부에서 SEO 점수·경쟁 비교 조회.
 */
class CompeteController extends Controller
{
    /** 트랙 목록 + 최신 N1/N2/N3. */
    public function tracks(Request $request)
    {
        $user = $request->user();
        $slots = $user->rankSlots()->latest()->get();
        $latest = PlaceSeoScore::whereIn('slot_id', $slots->pluck('id'))->where('is_mine', true)
            ->orderByDesc('ymd')->get()->groupBy('slot_id')->map(fn ($g) => $g->first());

        return response()->json([
            'tracks' => $slots->map(function ($s) use ($latest) {
                $sc = $latest[$s->id] ?? null;

                return [
                    'slot_id' => $s->id, 'keyword' => $s->keyword, 'place_id' => $s->place_id,
                    'place_name' => $s->place_name, 'category' => $s->category,
                    'analyzed_ymd' => $sc?->ymd?->toDateString(),
                    'n1' => $sc?->n1, 'n2' => $sc?->n2, 'n3' => $sc?->n3, 'rnk' => $sc?->rnk,
                ];
            })->values(),
        ]);
    }

    /** 분석 실행. */
    public function analyze(Request $request, PlaceRankSlot $slot, PlaceSeoAnalyzer $analyzer)
    {
        abort_unless($slot->user_id === $request->user()->id, 403);
        @set_time_limit(300); // 경쟁셋 상세 + 리뷰 수집은 수십 초 소요(동기)
        $res = $analyzer->analyze($slot, (int) $request->integer('detail_top', 10));
        if ($res['blocked']) {
            return response()->json(['message' => '조회 제한(nCaptcha 토큰 재발급 필요)', 'blocked' => true], 429);
        }

        return response()->json($res);
    }

    /** 경쟁 비교 상세(JSON). */
    public function show(Request $request, PlaceRankSlot $slot)
    {
        abort_unless($slot->user_id === $request->user()->id, 403);
        $ymd = PlaceSeoScore::where('slot_id', $slot->id)->max('ymd');
        if (! $ymd) {
            return response()->json(['analyzed' => false, 'rows' => [], 'mine' => null, 'explain' => null]);
        }

        $scores = PlaceSeoScore::where('slot_id', $slot->id)->where('ymd', $ymd)->get()->keyBy('place_id');
        $dailies = PlaceSeoDaily::where('ymd', $ymd)->whereIn('place_id', $scores->keys())->get()->keyBy('place_id');
        $serps = PlaceSeoSerp::where('slot_id', $slot->id)->where('ymd', $ymd)->orderByDesc('is_mine')->orderBy('rnk')->get();

        $rows = $serps->map(function ($s) use ($scores) {
            $sc = $scores[$s->place_id] ?? null;

            return [
                'rnk' => $s->rnk, 'name' => $s->name, 'place_id' => $s->place_id, 'is_mine' => (bool) $s->is_mine,
                'visitor_cnt' => $s->visitor_cnt, 'blog_cnt' => $s->blog_cnt, 'review_score' => $s->review_score,
                'd7' => $sc?->d7, 'n1' => $sc?->n1, 'n2' => $sc?->n2, 'n3' => $sc?->n3, 'tier' => $sc?->tier,
            ];
        })->values();

        $mineSerp = $serps->firstWhere('is_mine', true);
        $mineDaily = $dailies[$slot->place_id] ?? null;
        $mineScore = $scores[$slot->place_id] ?? null;
        $explain = null;
        if ($mineSerp && $mineDaily) {
            $cat = $slot->category ?: 'place';
            $explain = [
                'components' => PlaceScorer::keywordComponents($slot->keyword, $mineDaily->name, (string) $mineDaily->category, (string) $mineSerp->address, $mineDaily->tags ?? [], $cat),
                'seo' => PlaceScorer::seoItems($mineDaily->toArray(), $cat),
                'dims' => $mineScore ? $mineScore->only(['d1', 'd2', 'd3', 'd4', 'd5', 'd6', 'd7', 'd8', 'd9', 'd10', 'n1', 'n2', 'n3']) : null,
                'review_kw' => $mineDaily->review_kw,
                'review_weekly' => $mineDaily->review_weekly,
                'review_quality' => $mineDaily->review_quality,
            ];
        }

        $series = PlaceSeoScore::where('slot_id', $slot->id)->where('place_id', $slot->place_id)
            ->orderBy('ymd')->get(['ymd', 'n1', 'n2', 'n3', 'rnk'])
            ->map(fn ($r) => ['ymd' => $r->ymd->toDateString(), 'n1' => $r->n1, 'n2' => $r->n2, 'n3' => $r->n3, 'rnk' => $r->rnk]);

        return response()->json([
            'analyzed' => true, 'ymd' => $ymd instanceof \Illuminate\Support\Carbon ? $ymd->toDateString() : $ymd,
            'keyword' => $slot->keyword, 'place_name' => $slot->place_name,
            'rows' => $rows, 'explain' => $explain, 'series' => $series,
        ]);
    }
}
