<?php

namespace App\Http\Controllers\Api;

use App\Domain\Place\PlaceRankChecker;
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
            'place' => ['required', 'string', 'max:300'],
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
            'place' => ['required', 'string', 'max:300'],
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
            'place' => ['required', 'string', 'max:300'],
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
        $result = $checker->serpFetch($data['keyword'], $data['cat'] ?? '', null, $data['top'] ?? 100);

        return response()->json([
            'blocked' => $result['blocked'],
            'total' => $result['total'],
            'items' => array_map(fn ($it) => [
                'rank' => $it['rnk'],
                'place_id' => $it['place_id'],
                'name' => $it['name'],
                'review_score' => $it['review_score'],
                'visitor_cnt' => $it['visitor_cnt'],
                'blog_cnt' => $it['blog_cnt'],
            ], $result['items']),
        ]);
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
