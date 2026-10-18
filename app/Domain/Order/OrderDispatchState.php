<?php

namespace App\Domain\Order;

use App\Models\MarketingOrder;
use App\Models\MarketingOrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderDispatchState
{
    /** 외부 호출 전에 취소/환원과 동일한 주문 잠금으로 전송 권한을 확보한다. */
    public function beginDispatch(MarketingOrder $order, ?string &$blockedStatus = null, ?MarketingOrderItem $item = null, bool $explicitReissue = false): string|false
    {
        return DB::transaction(function () use ($order, &$blockedStatus, $item, $explicitReissue) {
            $locked = MarketingOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $blockedStatus = $locked->status;
            if ($item && ($item->fresh()->status === 'sent' || ($item->fresh()->status === 'canceled' && ! $explicitReissue))) {
                $blockedStatus = 'canceled';

                return false;
            }
            if (! in_array($locked->status, ['pending', 'processing']) ||
                DB::table('wallet_entries')->where('reference', 'refund:'.$locked->id)->exists()) {
                return false;
            }
            // Only an explicit request that observed cancellation can reopen this item.
            if ($item && $explicitReissue && $item->fresh()->status === 'canceled') {
                $item->update(['status' => 'pending']);
            }
            $previous = $locked->status;
            $locked->update(['status' => 'dispatching']);

            return $previous;
        });
    }

    /** Called under the order lock before releasing dispatching, including unknown outcomes. */
    public function recordExternalDispatch(MarketingOrder $order): void
    {
        MarketingOrder::whereKey($order->id)->whereNull('external_dispatched_at')->update(['external_dispatched_at' => now()]);
    }

    /** 취소/재발주도 전송과 같은 주문 잠금으로 최신 상태를 검증한다. */
    public function cancelDispatches(MarketingOrder $order, callable $cancel): mixed
    {
        return DB::transaction(function () use ($order, $cancel) {
            $locked = MarketingOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['pending', 'processing'])) {
                throw ValidationException::withMessages(['dispatch' => '발주 중이거나 취소된 주문입니다. 관리자 확인이 필요합니다.']);
            }
            app(PrepaidWallet::class)->assertNotRefunded($locked);

            return $cancel($locked);
        });
    }

    /** 미래 회차만 있는 승인도 최신 접수 상태에서만 활성화한다. */
    public function activatePending(MarketingOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = MarketingOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'pending') {
                app(PrepaidWallet::class)->assertNotRefunded($locked);
                $locked->update(['status' => 'processing']);
            }
        });
    }

    public function finishDispatch(MarketingOrder $order, bool $accepted = true, string $previous = 'processing'): void
    {
        DB::transaction(function () use ($order, $accepted, $previous) {
            $locked = MarketingOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'dispatching') {
                $locked->update(['status' => $accepted ? 'processing' : $previous]);
            }
        });
    }
}
