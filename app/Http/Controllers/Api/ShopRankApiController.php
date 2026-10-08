<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shopping\ShopRankSlotService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\Request;

/**
 * 쇼핑 순위체크 등록 API(2026-10-08) — 부스팅샵이 진행 중인 쇼핑 주문을 키 주인 계정의 순위추적에 넣는다.
 * 같은 상품 · 키워드는 다시 만들지 않고(꺼져 있으면 켬), 새로 만든 슬롯은 첫 순위를 바로 한 번 돌린다.
 */
class ShopRankApiController extends Controller
{
    public function __construct(private ShopRankSlotService $service) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'target' => ['required', 'string', 'max:500'],
            'keywords' => ['required', 'array', 'min:1', 'max:20'],
            'keywords.*' => ['string', 'max:120'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $out = [];
        foreach (array_unique(array_filter(array_map('trim', $data['keywords']))) as $kw) {
            try {
                $r = $this->service->ensureTracked($request->user(), $data['target'], $kw, $data['label'] ?? null);
            } catch (DomainException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            if ($r['status'] !== 'exists') {
                try {
                    $this->service->run($r['slot']);   // 첫 순위(실패 허용 — 매일 08 · 20시 자동 수집이 이어받는다)
                } catch (\Throwable) {
                }
            }
            $slot = $r['slot']->fresh();
            $out[] = ['keyword' => $kw, 'status' => $r['status'], 'slot_id' => $slot->id, 'last_rank' => $slot->last_rank, 'checked_at' => optional($slot->last_checked_at)->toIso8601String()];
        }

        return response()->json(['slots' => $out], 201);
    }
}
