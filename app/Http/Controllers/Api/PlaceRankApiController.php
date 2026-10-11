<?php

namespace App\Http\Controllers\Api;

use App\Domain\Place\RankSlotService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\Request;

/**
 * 플레이스 순위체크 등록 API(2026-10-11) — 부스팅샵이 진행 중인 플레이스 주문을 키 주인 계정의 순위추적에 넣는다.
 * 같은 플레이스 · 키워드는 다시 만들지 않고, 새로 만들거나 다시 켠 슬롯은 첫 순위를 바로 한 번 돌린다.
 */
class PlaceRankApiController extends Controller
{
    public function __construct(private RankSlotService $service) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'place' => ['required', 'string', 'max:1000'],
            'keywords' => ['required', 'array', 'min:1', 'max:20'],
            'keywords.*' => ['string', 'max:100'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $out = [];
        foreach (array_unique(array_filter(array_map('trim', $data['keywords']))) as $kw) {
            try {
                $r = $this->service->ensureTracked($request->user(), $data['place'], $kw, $data['label'] ?? null);
            } catch (DomainException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            if ($r['status'] !== 'exists') {
                try {
                    $this->service->run($r['slot']);   // 첫 순위(실패 허용 — 매일 11:30 · 16:30 자동 수집이 이어받는다)
                } catch (\Throwable) {
                }
            }
            $slot = $r['slot']->fresh();
            $out[] = ['keyword' => $kw, 'status' => $r['status'], 'slot_id' => $slot->id, 'last_rank' => $slot->last_rank, 'checked_at' => optional($slot->last_checked_at)->toIso8601String()];
        }

        return response()->json(['slots' => $out], 201);
    }
}
