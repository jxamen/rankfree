<?php

namespace App\Console\Commands;

use App\Domain\Place\RankSlotService;
use App\Models\MarketingOrder;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * 진행 중인 랭크프리 플레이스 주문을 순위체크에 등록(2026-10-11 담당 직원 「플레이스 주문이면 다 순위체크」).
 * 주문에 이미 순위추적이 연결돼 있으면(OrderRankTracker 가 진행중 전환 때 만든 슬롯) 그대로 두고 새로 만들지 않는다.
 * 같은 플레이스 · 키워드는 다시 만들지 않는다. 슬롯 주인: config('rankfree.shop_rank_auto_user_id') — 부스팅샵 API 키 주인과 같은 계정.
 */
class OrdersPlaceRankSync extends Command
{
    protected $signature = 'orders:place-rank-sync {--dry-run : 등록하지 않고 목록만}';

    protected $description = '진행 중인 플레이스 주문을 플레이스 순위체크에 등록';

    public function handle(RankSlotService $service): int
    {
        $user = User::find((int) config('rankfree.shop_rank_auto_user_id', 1));
        if (! $user) {
            $this->error('슬롯 주인 계정이 없습니다.');

            return self::FAILURE;
        }

        $counts = ['created' => 0, 'reactivated' => 0, 'exists' => 0, 'skipped' => 0, 'linked' => 0];
        $today = now()->timezone('Asia/Seoul')->toDateString();
        $orders = MarketingOrder::where('status', 'processing')->orderBy('id')->get()
            ->filter(fn (MarketingOrder $o) => $o->boostingService() === 'place')
            ->filter(function (MarketingOrder $o) use ($today) {
                $fv = (array) $o->field_values;
                $start = trim((string) ($fv['start_date'] ?? ''));
                $end = trim((string) ($fv['end_date'] ?? ''));

                return ($start === '' || $start <= $today) && ($end === '' || $end >= $today);
            });
        foreach ($orders as $o) {
            if ($o->place_rank_slot_id) {
                $counts['linked']++;   // 주문에 이미 연결된 순위추적이 있다

                continue;
            }
            $src = $o->placeSource();
            if (! $src) {
                $counts['skipped']++;
                $this->line("건너뜀 {$o->order_no} — 키워드 또는 플레이스 주소 없음");

                continue;
            }
            if ($this->option('dry-run')) {
                $this->line("대상 {$o->order_no}");

                continue;
            }
            try {
                $r = $service->ensureTracked($user, $src['url'], $src['keyword'], '랭크프리 '.$o->order_no);
            } catch (\Throwable $e) {
                $counts['skipped']++;
                $this->line("건너뜀 {$o->order_no} — ".$e->getMessage());

                continue;
            }
            $counts[$r['status']]++;
            if ($r['status'] !== 'exists') {
                try {
                    $service->run($r['slot']);
                } catch (\Throwable) {
                }
            }
        }

        $this->info('플레이스 순위체크 등록 — 새로 '.$counts['created'].' · 다시 켬 '.$counts['reactivated'].' · 이미 있음 '.$counts['exists'].' · 주문에 연결돼 있음 '.$counts['linked'].' · 건너뜀 '.$counts['skipped']);

        return self::SUCCESS;
    }
}
