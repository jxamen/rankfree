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

    /** Requires the caller's order lock. Never infer vendor cancellation from status. */
    public function assertAutomaticRefundAllowed(MarketingOrder $order): void
    {
        if (! DB::table('wallet_entries')->where('reference', 'order:'.$order->id)->exists()) {
            return;
        }
        $externalHistory = $order->external_dispatched_at || $order->dispatches()->where('channel', '!=', 'reward')
            ->where(function ($query) {
                $query->where('status', 'sent')
                    ->orWhere(fn ($q) => $q->where('status', 'canceled')->whereNotNull('sent_at'))
                    ->orWhere('response', 'like', '%결과 불명%');
            })->exists();
        if ($externalHistory || in_array($order->status, ['dispatching', 'processing'])) {
            throw ValidationException::withMessages(['status' => '외부 전송 이력 또는 처리 중인 주문은 자동 환원할 수 없습니다. 업체 취소 확인 후 전용 환원을 사용하세요.']);
        }
    }

    public function refund(MarketingOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = MarketingOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertAutomaticRefundAllowed($locked);
            $this->creditRefund($locked, '주문 환원 '.$locked->order_no);
        });
    }

    /** Only the explicit administrator action may bypass the external-history guard. */
    public function refundAfterVendorCancellation(MarketingOrder $order, User $admin, string $note): void
    {
        abort_unless($admin->isAdmin(), 403);
        if (trim($note) === '' || mb_strlen($note) > 160) {
            throw ValidationException::withMessages(['confirmation_note' => '업체 취소 확인 내용을 160자 이내로 입력하세요.']);
        }
        DB::transaction(function () use ($order, $admin, $note) {
            $locked = MarketingOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            // A live sender can still reach the vendor. Never release its claim here.
            if ($locked->status === 'dispatching') {
                throw ValidationException::withMessages(['status' => '발주 중·결과 불명 주문은 먼저 업체 대조가 필요합니다.']);
            }
            if (! DB::table('wallet_entries')->where('reference', 'order:'.$locked->id)->exists()) {
                throw ValidationException::withMessages(['status' => '잔액 결제 주문만 환원할 수 있습니다.']);
            }
            $this->creditRefund($locked, '업체 취소 확인 · 관리자 #'.$admin->id.' · '.trim($note));
            $locked->items()->update(['status' => 'canceled']);
            $locked->dispatches()->update(['status' => 'canceled']);
            $locked->restoreCoupon();
            $locked->update(['status' => 'canceled']);
        });
    }

    private function creditRefund(MarketingOrder $order, string $description): void
    {
        $debit = DB::table('wallet_entries')->where('reference', 'order:'.$order->id)->first();
        if ($debit) {
            $this->change($order->user_id, -(int) $debit->amount, 'refund:'.$order->id, $description);
        }
    }
}
