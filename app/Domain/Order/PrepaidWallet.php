<?php

namespace App\Domain\Order;

use App\Models\MarketingOrder;
use App\Models\Topup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrepaidWallet
{
    // Every balance mutation locks the existing user row; no lazy wallet creation race.
    public function change(int $userId, int $amount, string $reference, string $description): void
    {
        DB::transaction(function () use ($userId, $amount, $reference, $description) {
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
            if (DB::table('wallet_entries')->where('reference', $reference)->exists()) {
                return;
            }
            $balance = (int) $user->prepaid_balance + $amount;
            if ($balance < 0) {
                throw new OrderInputException('balance', '잔액이 부족합니다. 잔액·충전 화면에서 충전해 주세요.');
            }
            $user->forceFill(['prepaid_balance' => $balance])->save();
            DB::table('wallet_entries')->insert(['user_id' => $userId, 'amount' => $amount, 'balance_after' => $balance, 'reference' => $reference, 'description' => $description, 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    public function review(Topup $topup, User $admin, string $status): void
    {
        DB::transaction(function () use ($topup, $admin, $status) {
            $locked = Topup::whereKey($topup->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['topup' => '이미 처리된 충전 요청입니다.']);
            }
            if ($status === 'confirmed') {
                $this->change($locked->user_id, (int) $locked->amount, 'topup:'.$locked->id, '충전 '.$locked->code);
            }
            $locked->update(['status' => $status, 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
        });
    }

    public function assertNotRefunded(MarketingOrder $order): void
    {
        if (DB::table('wallet_entries')->where('reference', 'refund:'.$order->id)->exists()) {
            throw ValidationException::withMessages(['status' => '환원된 주문은 발주할 수 없습니다. 새 주문을 접수하세요.']);
        }
    }

    public function refund(MarketingOrder $order): void
    {
        $debit = DB::table('wallet_entries')->where('reference', 'order:'.$order->id)->first();
        if ($debit) {
            $this->change($order->user_id, -(int) $debit->amount, 'refund:'.$order->id, '주문 환원 '.$order->order_no);
        }
    }
}
