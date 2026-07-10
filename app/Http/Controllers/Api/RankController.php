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
            'used' => $user->rankSlotsUsed(),
            'limit' => $user->rankSlotLimit(),
            'slots' => $user->rankSlots()->latest()->get()->map(fn ($s) => $this->slotJson($s))->values(),
        ]);
    }

    /** 슬롯 추가. */
    public function store(Request $request, RankSlotService $service)
    {
        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:100'],
            'place' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $slot = $service->add($request->user(), $data['keyword'], $data['place'], $data['label'] ?? null);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['slot' => $this->slotJson($slot)], 201);
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
            'place' => ['required', 'string', 'max:255'],
        ]);
        $placeId = PlaceRankChecker::extractPlaceId($data['place']);
        $result = $checker->check($data['keyword'], $placeId, $placeId ? null : $data['place']);

        return response()->json(['result' => $result]);
    }

    private function slotJson(PlaceRankSlot $s): array
    {
        return [
            'id' => $s->id,
            'keyword' => $s->keyword,
            'place_id' => $s->place_id,
            'place_name' => $s->place_name,
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
