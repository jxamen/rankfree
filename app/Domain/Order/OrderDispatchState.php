<?php

namespace App\Domain\Order;

use App\Models\MarketingOrder;

class OrderDispatchState
{
    /** 외부 호출 전에 취소/환원과 동일한 주문 잠금으로 전송 권한을 확보한다. */
    public function beginDispatch(MarketingOrder $order): string|false
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($order) {
            $locked = MarketingOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['pending', 'processing']) ||
                \Illuminate\Support\Facades\DB::table('wallet_entries')->where('reference', 'refund:'.$locked->id)->exists()) {
                return false;
            }
            $previous = $locked->status;
            $locked->update(['status' => 'dispatching']);

            return $previous;
        });
    }

    /** 미래 회차만 있는 승인도 최신 접수 상태에서만 활성화한다. */
    public function activatePending(MarketingOrder $order): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($order) {
            $locked = MarketingOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'pending') {
                app(PrepaidWallet::class)->assertNotRefunded($locked);
                $locked->update(['status' => 'processing']);
            }
        });
    }

    public function finishDispatch(MarketingOrder $order, bool $accepted = true, string $previous = 'processing'): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($order, $accepted, $previous) {
            $locked = MarketingOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'dispatching') {
                $locked->update(['status' => $accepted ? 'processing' : $previous]);
            }
        });
    }

}
