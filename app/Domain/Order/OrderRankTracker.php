<?php

namespace App\Domain\Order;

use App\Domain\Shopping\NaverShoppingRankService;
use App\Domain\Shopping\ShopRankSlotService;
use App\Models\MarketingOrder;
use App\Models\ShopRankSlot;
use Illuminate\Support\Facades\Log;

/**
 * 주문 → 쇼핑 순위추적 자동 등록 (2026-07-23).
 * 주문이 진행중(processing)으로 바뀌면 주문 소유 회원 명의로 (키워드 × 상품 URL) 추적 슬롯을 만들고
 * 주문에 연결한다 — 광고주가 자신의 주문 내역에서 순위를 바로 확인할 수 있게.
 * 이미 같은 추적이 있으면 그 슬롯을 연결(중복 생성 없음). 한도 초과 등 실패는 주문 흐름에 영향 없음.
 */
class OrderRankTracker
{
    public function __construct(
        private ShopRankSlotService $slots,
        private NaverShoppingRankService $shop,
    ) {}

    public function register(MarketingOrder $order): ?ShopRankSlot
    {
        if ($order->shop_rank_slot_id) {
            return $order->shopRankSlot;
        }
        $src = $order->shopKeywordSource();   // ['keyword' => ..., 'url' => ...] — 쇼핑 유입 주문만
        $user = $order->user;
        if (! $src || ! $user) {
            return null;
        }

        $slot = null;
        try {
            $res = $this->slots->addMany($user, $src['url'], [$src['keyword']], '주문 '.$order->order_no);
            $slot = $res['created'][0] ?? null;
        } catch (\DomainException $e) {
            Log::warning('주문 순위추적 자동 등록 실패', ['order' => $order->order_no, 'e' => $e->getMessage()]);
        }

        // 이미 추적 중(중복 스킵) — 같은 키워드×대상 슬롯을 찾아 연결
        if (! $slot) {
            $target = $this->shop->resolveTarget($src['url']);
            $slot = ShopRankSlot::where('user_id', $user->id)->where('keyword', $src['keyword'])
                ->where(fn ($q) => ($target['product_id'] ?? '') !== ''
                    ? $q->where('product_id', $target['product_id'])
                    : $q->where('mall_name', $target['mall_name']))
                ->latest('id')->first();
        }
        if (! $slot) {
            return null;
        }

        // 연결 저장 — saveQuietly 로 updated 이벤트 재귀 방지
        $order->forceFill(['shop_rank_slot_id' => $slot->id])->saveQuietly();

        // 첫 순위 1회(베스트 에포트) — 실패해도 매시간 자동 수집이 채운다
        try {
            $this->slots->run($slot);
        } catch (\Throwable) {
        }

        return $slot;
    }
}
