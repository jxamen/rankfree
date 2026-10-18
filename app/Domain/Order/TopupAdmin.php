<?php

namespace App\Domain\Order;

use App\Models\Topup;
use App\Models\User;
use App\Support\Aligo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TopupAdmin
{
    public function apply(Topup $topup, User $admin, array $data): void
    {
        abort_unless($admin->isAdmin(), 403);
        DB::transaction(function () use ($topup, $admin, $data) {
            $t = Topup::whereKey($topup->id)->lockForUpdate()->firstOrFail();
            abort_if($t->deleted_at, 404);
            $before = $t->getAttributes();
            $action = $data['action'];
            $changes = [];
            $require = function (bool $ok) {
                if (! $ok) {
                    throw ValidationException::withMessages(['topup' => '현재 상태에서 처리할 수 없습니다. 잔액과 처리 이력을 확인하세요.']);
                }
            };
            if ($action === 'vat_sms') {
                abort_if(DB::table('topup_audits')->where('topup_id', $t->id)->where('action', 'vat_sms')->where('created_at', '>=', now()->subDay())->exists(), 409, '24시간 이내 부가세 요청 이력이 있습니다. 발송 결과를 대조하세요.');
                $phone = (string) DB::table('users')->where('id', $t->user_id)->value('phone');
                abort_unless(preg_match('/^01[016789]\d{7,8}$/', preg_replace('/\D/', '', $phone)), 422, '회원 휴대전화가 없습니다.');
                $claim = DB::table('topup_audits')->insertGetId(['topup_id' => $t->id, 'actor' => (string) $admin->id, 'action' => 'vat_sms', 'before_state' => '{}', 'after_state' => '{"sms":"unknown"}', 'created_at' => now()]);
                DB::afterCommit(function () use ($claim, $phone) {
                    $message = '적립금 충전시 부가세는 별도입니다. 부가세 입금 부탁 드립니다.';
                    $ok = (Aligo::configured() && Aligo::sendSms($phone, $message));
                    DB::table('topup_audits')->where('id', $claim)->update(['after_state' => json_encode(['sms' => $ok ? 'sent' : 'failed_or_unknown'])]);
                });

                return;
            }
            if ($action === 'revoke') {
                $require($t->status === 'confirmed');
                app(PrepaidWallet::class)->change($t->user_id, -(int) $t->amount, 'topup-revoke:'.$t->id.':'.$t->approval_cycle, '충전 승인취소 관리자 #'.$admin->id);
                $changes = ['status' => 'pending', 'approval_cycle' => $t->approval_cycle + 1];
            } elseif ($action === 'cancel') {
                $require($t->status === 'pending');
                $changes = ['status' => 'rejected'];
            } elseif ($action === 'restore') {
                $require($t->status === 'rejected');
                $changes = ['status' => 'pending'];
            } elseif ($action === 'delete') {
                $require($t->status === 'rejected');
                $changes = ['deleted_at' => now()];
            } elseif ($action === 'edit') {
                $require($t->status === 'pending' && (int) $t->approval_cycle === 0);
                $changes = ['company' => $data['company'] ?? '', 'depositor' => $data['depositor'], 'amount' => $data['amount']];
            } elseif ($action === 'bank') {
                $changes = ['bank_received' => $data['value']];
            } elseif ($action === 'vat') {
                $changes = ['vat_included' => $data['value']];
            } elseif ($action === 'invoice') {
                $require($t->status === 'confirmed' || ! $data['value']);
                $changes = ['invoice_issued' => $data['value']];
            } else {
                abort(422);
            }
            $t->update($changes + ['reviewed_by' => $admin->id, 'reviewed_at' => now()]);
            self::audit($t, $admin, $action, $before);
        }, 5);
    }

    public static function audit(Topup $t, User $admin, string $action, array $before): void
    {
        DB::table('topup_audits')->insert(['topup_id' => $t->id, 'actor' => (string) $admin->id, 'action' => $action, 'before_state' => json_encode($before, JSON_UNESCAPED_UNICODE), 'after_state' => json_encode($t->fresh()->getAttributes(),JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
    }
}
