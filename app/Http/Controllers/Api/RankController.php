<?php

namespace App\Http\Controllers\Api;

use App\Domain\Place\PlaceRankChecker;
use App\Domain\Place\PlaceScorer;
use App\Domain\Place\PlaceSeoAnalyzer;
use App\Domain\Place\RankSlotService;
use App\Http\Controllers\Controller;
use App\Models\PlaceRankSlot;
use DomainException;
use Illuminate\Http\Request;

/**
 * 순위 추적 API (auth.ext Bearer 토큰). 크롬 확장·외부에서 사용.
 * 웹 콘솔과 동일 로직(RankSlotService) 공유.
 */
class RankController extends Controller
{
    /** 내 추적 슬롯 목록 + 사용량. */
    public function slots(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'used' => $user->rankSlotsUsedTotal(),
            'limit' => $user->rankSlotLimit(),
            'slots' => $user->rankSlots()->with('records')->latest()->get()->map(fn ($s) => $this->slotJson($s))->values(),
        ]);
    }

    /**
     * 슬롯 추가. URL/ID 1개 + 키워드 N개(keywords[]) 또는 단건(keyword).
     * 업체명·카테고리 자동조회 후 키워드별 슬롯 생성.
     */
    public function store(Request $request, RankSlotService $service)
    {
        $data = $request->validate([
            'place' => ['required', 'string', 'max:1000'],
            'keyword' => ['required_without:keywords', 'nullable', 'string', 'max:100'],
            'keywords' => ['required_without:keyword', 'nullable', 'array', 'min:1'],
            'keywords.*' => ['string', 'max:100'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $keywords = $data['keywords'] ?? [];
        if (! empty($data['keyword'])) {
            $keywords[] = $data['keyword'];
        }

        try {
            $res = $service->addMany($request->user(), $data['place'], $keywords, $data['label'] ?? null);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'place' => $res['place'],
            'created' => array_map(fn ($s) => $this->slotJson($s), $res['created']),
            'skipped' => $res['skipped'],
        ], 201);
    }

    /** 플레이스 메타 자동조회(업체명·카테고리·정규 m.place URL). 슬롯 생성 없음. */
    public function resolve(Request $request, RankSlotService $service)
    {
        $data = $request->validate([
            'place' => ['required', 'string', 'max:1000'],
        ]);

        return response()->json(['place' => $service->resolvePlace($data['place'])]);
    }

    /** 즉시 순위 갱신. */
    public function run(Request $request, PlaceRankSlot $slot, RankSlotService $service)
    {
        abort_unless($slot->user_id === $request->user()->id, 403);
        $result = $service->run($slot);

        return response()->json([
            'result' => $result,
            'slot' => $this->slotJson($slot->load('records')),
        ]);
    }

    public function destroy(Request $request, PlaceRankSlot $slot)
    {
        abort_unless($slot->user_id === $request->user()->id, 403);
        $slot->delete();

        return response()->json(['deleted' => true]);
    }

    /** 1회성 순위 조회(슬롯 없이). */
    public function check(Request $request, PlaceRankChecker $checker)
    {
        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:100'],
            'place' => ['required', 'string', 'max:1000'],
        ]);
        $placeId = $checker->resolvePlaceId($data['place']);
        $result = $checker->check($data['keyword'], $placeId, $placeId ? null : $data['place']);

        return response()->json(['result' => $result]);
    }

    /**
     * 키워드 상위 리스트 순위 조회(슬롯 없이) — map.naver 플레이스 리스트 배지용.
     * pcmap GraphQL 오가닉 순위(광고 제외·서울 고정 좌표). cat은 pcmap 경로에서 판별.
     */
    public function serp(Request $request, PlaceRankChecker $checker)
    {
        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:100'],
            'cat' => ['nullable', 'string', 'max:30'],
            'top' => ['nullable', 'integer', 'min:10', 'max:300'],
        ]);
        $cat = $data['cat'] ?? '';
        $result = $checker->serpFetch($data['keyword'], $cat, null, $data['top'] ?? 100);
        $items = $result['items'];

        // N1/N2/N3 계산용 정규화 모집단(상위 리스트 전체 · P90 기준)
        $visArr = array_map(fn ($i) => $i['visitor_cnt'], $items);
        $blogArr = array_map(fn ($i) => $i['blog_cnt'], $items);
        $saveArr = array_map(fn ($i) => $i['save_cnt'], $items);
        $scoreArr = array_map(fn ($i) => $i['review_score'], $items);
        $bookingArr = array_map(fn ($i) => $i['booking_cnt'], $items);
        $imgArr = array_map(fn ($i) => $i['img_cnt'] ?? null, $items);
        $catForScore = $cat !== '' ? $cat : 'place';

        $out = array_map(function ($it) use ($data, $catForScore, $visArr, $blogArr, $saveArr, $scoreArr, $bookingArr, $imgArr) {
            // 간이 점수 — detail=null(D7/D9/D10 결측, N2는 간이). 업체별 추가 요청 0.
            $sc = PlaceScorer::computeScores($it, null, $data['keyword'], $catForScore, $visArr, $blogArr, $saveArr, $scoreArr, $bookingArr, [], [], [], $imgArr);

            return [
                'rank' => $it['rnk'],
                'place_id' => $it['place_id'],
                'name' => $it['name'],
                'review_score' => $it['review_score'],
                'visitor_cnt' => $it['visitor_cnt'],
                'blog_cnt' => $it['blog_cnt'],
                'save_cnt' => $it['save_cnt'],
                'booking_cnt' => $it['booking_cnt'],
                'img_cnt' => $it['img_cnt'] ?? null,
                'place_plus' => ! empty($it['place_plus']),
                'new_opening' => ! empty($it['new_opening']),
                'n1' => $sc['n1'],
                'n2' => $sc['n2'],
                'n3' => $sc['n3'],
                'tier' => $sc['tier'],
                'd' => [
                    'd1' => $sc['d1'], 'd2' => $sc['d2'], 'd3' => $sc['d3'], 'd4' => $sc['d4'], 'd5' => $sc['d5'],
                    'd6' => $sc['d6'], 'd7' => $sc['d7'], 'd9' => $sc['d9'], 'd10' => $sc['d10'],
                ],
            ];
        }, $items);

        return response()->json([
            'blocked' => $result['blocked'],
            'total' => $result['total'],
            'items' => $out,
        ]);
    }

    /**
     * 단일 매장 정밀 분석(매장분석) — 완전 N1/N2/N3 + D1~D10(D7 정보충실·D9 최근활동·D10 리뷰어영향력 포함).
     * 업체 상세 + 리뷰 주별 수집이 있어 수 초 소요.
     */
    public function placeDetail(Request $request, PlaceSeoAnalyzer $analyzer)
    {
        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:100'],
            'place_id' => ['required', 'string', 'max:30'],
            'cat' => ['nullable', 'string', 'max:30'],
        ]);
        $sc = $analyzer->analyzeOne($data['keyword'], $data['cat'] ?? '', $data['place_id']);
        if (! $sc) {
            return response()->json(['message' => '분석이 차단되었거나 실패했습니다(순위 토큰 필요).'], 503);
        }

        return response()->json(['detail' => [
            'n1' => $sc['n1'], 'n2' => $sc['n2'], 'n3' => $sc['n3'], 'tier' => $sc['tier'],
            'rank' => $sc['rnk'], 'name' => $sc['name'],
            // 수동 매장분석(시장분석 리스트 없이 진입)에서도 지수 요약 카드를 채울 수 있게 counts 포함
            'visitor_cnt' => $sc['visitor_cnt'] ?? null, 'blog_cnt' => $sc['blog_cnt'] ?? null, 'save_cnt' => $sc['save_cnt'] ?? null,
            'd' => [
                'd1' => $sc['d1'], 'd2' => $sc['d2'], 'd3' => $sc['d3'], 'd4' => $sc['d4'], 'd5' => $sc['d5'],
                'd6' => $sc['d6'], 'd7' => $sc['d7'], 'd9' => $sc['d9'], 'd10' => $sc['d10'],
            ],
            // web 경쟁분석 explain과 동일한 상세 지표
            'kc' => $sc['kc'] ?? null,               // N1 요소 L/B/T/M
            'seo' => $sc['seo'] ?? [],               // 정보충실 체크리스트(D7 세부)
            'category' => $sc['category'] ?? '',
            'rep_keywords' => $sc['rep_keywords'] ?? [],
            'review_kw' => $sc['review_kw'] ?? null,
            'review_quality' => $sc['review_quality'] ?? null,
        ]]);
    }

    private function slotJson(PlaceRankSlot $s): array
    {
        return [
            'id' => $s->id,
            'keyword' => $s->keyword,
            'place_id' => $s->place_id,
            'place_name' => $s->place_name,
            'place_url' => $s->place_url,
            'label' => $s->label,
            'category' => $s->category,
            'last_rank' => $s->last_rank,
            'last_review_count' => $s->last_review_count,
            'last_checked_at' => $s->last_checked_at?->toIso8601String(),
            'history' => $s->relationLoaded('records')
                ? $s->records->map(fn ($r) => ['date' => $r->checked_date->toDateString(), 'rank' => $r->rank])->values()
                : null,
        ];
    }
}
