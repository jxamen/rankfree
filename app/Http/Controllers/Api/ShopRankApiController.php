<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shopping\ShopRankSlotService;
use App\Http\Controllers\Controller;
use App\Models\ShopRankSlot;
use DomainException;
use Illuminate\Http\Request;

/**
 * 쇼핑 순위체크 등록 API(2026-10-08) — 부스팅샵이 진행 중인 쇼핑 주문을 키 주인 계정의 순위추적에 넣는다.
 * 같은 상품 · 키워드는 다시 만들지 않고(꺼져 있으면 켬), 새로 만든 슬롯은 첫 순위를 바로 한 번 돌린다.
 */
class ShopRankApiController extends Controller
{
    public function __construct(private ShopRankSlotService $service) {}

    /**
     * 쇼핑 순위 읽기(2026-10-14, 셀프 마케팅 앱) — 키 주인 계정의 슬롯 목록 + 사용량.
     * ?label= 정확히 같은 라벨만(앱 사용자별 구분용) · ?active=1|0 · ?limit=1~200(기본 100) · ?history=0 이면 일별 기록 생략.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $q = $user->shopRankSlots()->latest('id');
        if ($request->filled('label')) {
            $q->where('label', (string) $request->query('label'));
        }
        if ($request->query('active') === '1' || $request->query('active') === '0') {
            $q->where('is_active', $request->query('active') === '1');
        }
        $withHistory = $request->query('history', '1') !== '0';
        if ($withHistory) {
            $q->with(['records' => fn ($r) => $r->orderByDesc('checked_date')->limit(60)]);
        }
        $slots = $q->limit(max(1, min(200, (int) $request->query('limit', 100))))->get();

        return response()->json([
            'used' => $user->rankSlotsUsedTotal(),
            'limit' => $user->rankSlotLimit(),
            'slots' => $slots->map(fn (ShopRankSlot $s) => $this->slotJson($s, $withHistory))->values(),
        ]);
    }

    /** 슬롯 1개 + 일별 기록(최근 60일). 다른 계정의 슬롯은 404. */
    public function show(Request $request, int $slot)
    {
        $s = $request->user()->shopRankSlots()->with(['records' => fn ($r) => $r->orderByDesc('checked_date')->limit(60)])->find($slot);
        if (! $s) {
            return response()->json(['message' => '슬롯을 찾을 수 없습니다.'], 404);
        }

        return response()->json(['slot' => $this->slotJson($s, true)]);
    }

    private function slotJson(ShopRankSlot $s, bool $withHistory): array
    {
        return [
            'id' => $s->id,
            'keyword' => $s->keyword,
            'label' => $s->label,
            'is_active' => (bool) $s->is_active,
            'product_id' => $s->product_id,
            'product_title' => $s->product_title,
            'mall_name' => $s->mall_name,
            'product_url' => $s->product_url,
            'last_rank' => $s->last_rank,                  // null = 아직 조회 전 또는 순위권 밖
            'last_price' => $s->last_price,
            'last_checked_at' => $s->last_checked_at?->toIso8601String(),
            'history' => $withHistory
                ? $s->records->map(fn ($r) => ['date' => $r->checked_date->toDateString(), 'rank' => $r->rank, 'price' => $r->price])->values()
                : null,
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'target' => ['required', 'string', 'max:500'],
            'keywords' => ['required', 'array', 'min:1', 'max:20'],
            'keywords.*' => ['string', 'max:120'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        // 첫 순위는 상위 20위면 즉시, 그 밖이면 확장 작업 대기열에 넣고 바로 응답(웹 요청이 40초 기다리다 503 나던 것, 2026-10-08 56008)
        config(['rankfree.shopping.worker_wait_sec' => 3]);

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
