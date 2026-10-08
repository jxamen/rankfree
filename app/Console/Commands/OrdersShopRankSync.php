<?php

namespace App\Console\Commands;

use App\Domain\Shopping\ShopRankSlotService;
use App\Models\MarketingOrder;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * 진행 중인 랭크프리 쇼핑 주문을 순위체크에 등록(2026-10-08 담당 직원 「쇼핑 건이면 다 랭크프리에서 순위체크」).
 * 같은 상품 · 키워드는 다시 만들지 않는다(꺼져 있으면 켬). 새로 만든 슬롯은 첫 순위를 한 번 돌린다.
 * 슬롯 주인: config('rankfree.shop_rank_auto_user_id') — 부스팅샵 API 키 주인과 같은 계정.
 */
class OrdersShopRankSync extends Command
{
    protected $signature = 'orders:shop-rank-sync {--dry-run : 등록하지 않고 목록만}';

    protected $description = '진행 중인 쇼핑 주문을 쇼핑 순위체크에 등록';

    public function handle(ShopRankSlotService $service): int
    {
        $user = User::find((int) config('rankfree.shop_rank_auto_user_id', 1));
        if (! $user) {
            $this->error('슬롯 주인 계정이 없습니다.');

            return self::FAILURE;
        }

        $counts = ['created' => 0, 'reactivated' => 0, 'exists' => 0, 'skipped' => 0];
        // 진행 중 = 상태 processing 이고 오늘이 시작일~종료일 안(날짜가 비어 있으면 포함) — 기간이 끝났는데 상태만 남은 주문은 뺀다
        $today = now()->timezone('Asia/Seoul')->toDateString();
        $orders = MarketingOrder::where('status', 'processing')->orderBy('id')->get()
            ->filter(fn (MarketingOrder $o) => $o->boostingService() === 'shopping')
            ->filter(function (MarketingOrder $o) use ($today) {
                $fv = (array) $o->field_values;
                $start = trim((string) ($fv['start_date'] ?? ''));
                $end = trim((string) ($fv['end_date'] ?? ''));

                return ($start === '' || $start <= $today) && ($end === '' || $end >= $today);
            });
        foreach ($orders as $o) {
            $src = $o->shopKeywordSource();
            if (! $src || trim((string) $src['keyword']) === '' || trim((string) $src['url']) === '') {
                $counts['skipped']++;
                $this->line("건너뜀 {$o->order_no} — 키워드 또는 상품 URL 없음");

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

        $this->info('쇼핑 순위체크 등록 — 새로 '.$counts['created'].' · 다시 켬 '.$counts['reactivated'].' · 이미 있음 '.$counts['exists'].' · 건너뜀 '.$counts['skipped']);

        return self::SUCCESS;
    }
}
