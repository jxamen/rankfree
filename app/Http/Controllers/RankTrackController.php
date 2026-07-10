<?php

namespace App\Http\Controllers;

use App\Domain\Place\RankSlotService;
use App\Models\PlaceRankSlot;
use DomainException;
use Illuminate\Http\Request;

/** 순위 추적 슬롯 — 웹 콘솔 (등록/조회/삭제/즉시갱신). 로직은 RankSlotService 공유. */
class RankTrackController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return view('console.rank', [
            'slots' => $user->rankSlots()->with('records')->latest()->get(),
            'usedSlots' => $user->rankSlotsUsed(),
            'maxSlots' => $user->rankSlotLimit(),
        ]);
    }

    public function store(Request $request, RankSlotService $service)
    {
        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:100'],
            'place' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $service->add($request->user(), $data['keyword'], $data['place'], $data['label'] ?? null);
        } catch (DomainException $e) {
            return back()->withErrors(['place' => $e->getMessage()]);
        }

        return back()->with('status', '추적 슬롯을 추가했습니다. "지금 확인"으로 순위를 갱신하세요.');
    }

    public function run(Request $request, PlaceRankSlot $slot, RankSlotService $service)
    {
        abort_unless($slot->user_id === $request->user()->id, 403);
        $r = $service->run($slot);

        $msg = $r['blocked']
            ? '조회가 일시적으로 제한됐습니다 (nCaptcha 토큰 재발급 필요).'
            : ($r['found'] ? $slot->keyword.' 순위 '.$r['rank'].'위' : '300위 밖입니다.');

        return back()->with('status', $msg);
    }

    public function destroy(Request $request, PlaceRankSlot $slot)
    {
        abort_unless($slot->user_id === $request->user()->id, 403);
        $slot->delete();

        return back()->with('status', '추적 슬롯을 삭제했습니다.');
    }
}
