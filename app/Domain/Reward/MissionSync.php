<?php

namespace App\Domain\Reward;

use App\Models\RewardMission;
use App\Support\RewardDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 세부주문서 → 리워드 미션 동기화 (design-01 §1-2·§1-4, C9·C12).
 * - 필터: 리워드 풀 벤더(config('reward.pool_vendor_id') — app_settings 로 어드민이 지정, .env 아님)
 * - 단가: unit_revenue = marketing_orders.total_price ÷ Σ(그 주문 전체 회차 quantity, 전 벤더 포함) — C9
 * - 신규 미션은 draft(정답 미입력) 로 만들고, 정답이 있으면 active 로 승격한다. unit_revenue=0 은 draft 로 막는다.
 * - 한도 하향 시 오늘 카운터에 overflow 를 기록한다(C12 — 초과의 유일한 경로가 동기화 지연이다).
 */
class MissionSync
{
    /** @return array{synced:int, created:int, drafted:int, ended:int, canceled:int}|array{error:string} */
    public function sync(bool $incremental = false, ?int $orderId = null, ?Carbon $since = null): array
    {
        $vendorId = (int) config('reward.pool_vendor_id');
        if ($vendorId <= 0) {
            Log::warning('reward.sync: 리워드 풀 벤더 미지정 — 동기화 중단 (어드민 환경설정 reward.pool_vendor_id)');

            return ['error' => 'pool_vendor_unset'];
        }

        $today = RewardDay::current();

        $q = DB::table('marketing_order_items as moi')
            ->join('marketing_orders as mo', 'mo.id', '=', 'moi.order_id')
            ->leftJoin('shop_keyword_analyses as ska', 'ska.marketing_order_id', '=', 'mo.id')
            ->leftJoin('shop_product_infos as spi', 'spi.channel_product_id', '=', 'ska.product_id')
            ->where('moi.vendor_id', $vendorId)
            ->where('moi.status', 'sent')
            ->where('mo.status', 'processing')
            ->whereDate('moi.end_date', '>=', $today)
            ->orderBy('moi.id')
            ->select([
                'moi.id as order_item_id', 'moi.order_id', 'moi.day_no', 'moi.work_date', 'moi.end_date',
                'moi.quantity', 'moi.short_url', 'moi.vendor_id',
                'mo.product_id', 'mo.user_id as advertiser_user_id', 'mo.total_price',
                'ska.mall_name', 'ska.product_title', 'ska.product_price', 'ska.core_keyword', 'ska.product_url',
                'spi.thumbnail_url', 'spi.seller_tags',
            ]);

        if ($orderId) {
            $q->where('moi.order_id', $orderId);
        } elseif ($incremental) {
            $q->where('moi.updated_at', '>=', $since ?? now()->subMinutes(15));   // 증분: 마지막 주기 − 여유 10분
        }

        // order_item_id 로 유일화 — ska 가 주문당 여러 행이어도 마지막 행을 쓴다
        $rows = $q->get()->keyBy('order_item_id');

        // C9 분모: 그 주문의 전체 회차 수량 합(다른 벤더 회차 포함 — 퀴즈농장 몫만 세면 단가가 부풀려진다)
        $qtySums = $rows->isEmpty() ? collect() : DB::table('marketing_order_items')
            ->whereIn('order_id', $rows->pluck('order_id')->unique())
            ->groupBy('order_id')
            ->selectRaw('order_id, SUM(quantity) as qty_sum')
            ->pluck('qty_sum', 'order_id');

        $stats = ['synced' => 0, 'created' => 0, 'drafted' => 0, 'ended' => 0, 'canceled' => 0];

        foreach ($rows as $row) {
            $days = (int) Carbon::parse($row->work_date)->diffInDays(Carbon::parse($row->end_date)) + 1;   // 종료일 포함
            $qtySum = (float) ($qtySums[$row->order_id] ?? 0);
            $unitRevenue = $qtySum > 0 ? round((float) $row->total_price / $qtySum, 2) : 0.0;

            $mission = RewardMission::query()->firstOrNew(['order_item_id' => $row->order_item_id]);
            $isNew = ! $mission->exists;

            $mission->fill([
                'order_id' => $row->order_id,
                'product_id' => $row->product_id,
                'advertiser_user_id' => $row->advertiser_user_id,
                'vendor_id' => $row->vendor_id,
                'day_no' => $row->day_no,
                'starts_on' => $row->work_date,
                'ends_on' => $row->end_date,
                'daily_quota' => (int) $row->quantity,                      // per_day 확정(HANDOFF §7)
                'total_quota' => (int) $row->quantity * $days,
                'unit_revenue' => $unitRevenue,
                'landing_url' => $row->short_url,
                'shop_name' => $row->mall_name,
                'product_title' => $row->product_title,
                'product_price' => $row->product_price,
                'product_image_url' => $row->thumbnail_url,
                'keyword' => $row->core_keyword,
                'product_url' => $row->product_url,
                'tags' => is_string($row->seller_tags) ? (json_decode($row->seller_tags, true) ?: null) : $row->seller_tags,
                'synced_at' => now(),
            ]);

            // 제목·설명 자동 조립 — 운영자가 채운 값은 덮지 않는다(design-01 §1-4)
            if ($mission->title === '' || $mission->title === null) {
                $mission->title = Str::limit(trim(($row->mall_name ? $row->mall_name.' ' : '').($row->product_title ?? '').' 최저가 찾기'), 77);
            }
            if ($mission->description === '' || $mission->description === null) {
                $mission->description = Str::limit(sprintf(
                    '%s 검색 결과에서 %s 상품 가격을 확인하고 오면 %s %d개를 받아요.',
                    $row->core_keyword ?? '쇼핑', $row->mall_name ?? '해당', $mission->reward_item ?? 'water', $mission->reward_count ?? 1,
                ), 197);
            }

            if ($isNew) {
                $mission->status = 'draft';
                $stats['created']++;
            }

            // 상태 전이 — 운영자 조작(paused 등)은 존중하고, draft↔active 만 자동으로 오간다
            if ($unitRevenue <= 0) {
                // 분모 0(수량 미생성) 또는 무료 주문 — 청구 불가 상태로 노출을 막는다(§6-1)
                if ($mission->status === 'active') {
                    $mission->status = 'draft';
                }
                Log::warning("reward.sync: unit_revenue=0 — 미션 #{$row->order_item_id} draft 유지(주문 {$row->order_id})");
                $stats['drafted']++;
            } elseif ($mission->status === 'draft' && $this->isGradable($mission)) {
                $mission->status = 'active';   // 채점 가능한 draft 는 노출 가능
            } elseif ($mission->status === 'draft' && $isNew) {
                Log::warning("reward.sync: 채점 근거 없음 — 미션 #{$mission->order_item_id} draft 생성(상품 해시태그 미수집 또는 고정 정답 미입력)");
            }

            $mission->save();
            $stats['synced']++;

            $this->syncTodayCounter($mission, $today);
        }

        if (! $incremental && ! $orderId) {
            $stats['ended'] = RewardMission::query()
                ->whereIn('status', ['draft', 'active', 'paused'])
                ->whereDate('ends_on', '<', $today)
                ->update(['status' => 'ended']);

            // 원본 회차가 취소되면 미션도 취소 — 전량 대조에서만 수행
            $canceledIds = DB::table('marketing_order_items')
                ->where('vendor_id', $vendorId)->where('status', 'canceled')->pluck('id');
            $stats['canceled'] = $canceledIds->isEmpty() ? 0 : RewardMission::query()
                ->whereIn('order_item_id', $canceledIds)
                ->whereNotIn('status', ['canceled'])
                ->update(['status' => 'canceled']);
        }

        $this->ensureDailyCounters();

        // 변경이 있으면 스냅샷·캐시 버전 갱신(C11) — v{ver} 키가 갈려 모든 사본이 무력화된다
        if ($stats['synced'] > 0 || $stats['ended'] > 0 || $stats['canceled'] > 0) {
            app(MissionSnapshot::class)->build();
            RewardCache::bumpVersion();
        }

        return $stats;
    }

    /**
     * 채점 가능한가 — MissionGrader 의 판정 순서와 같아야 한다(여기가 더 엄격하면 미션이 draft 에 갇힌다).
     * 해시태그형은 상품 태그(spi.seller_tags 스냅샷)가 곧 정답이라 운영자 입력이 필요 없다.
     * 고정 정답형(태그 없는 미션)만 answer 입력을 기다린다.
     */
    private function isGradable(RewardMission $mission): bool
    {
        $tags = array_filter((array) $mission->tags, fn ($t) => is_string($t) && trim($t) !== '');

        return $tags !== [] || filled($mission->answer);
    }

    /**
     * 오늘·이후 카운터에 새 한도를 반영하고, 한도 하향으로 used > quota 가 되면 overflow 를 기록한다(C12).
     * 카운터 행이 아직 없으면 ensureDailyCounters 가 만든다.
     * 익일 행까지 갱신하는 이유: 선생성된 익일 행은 insertOrIgnore 에 막혀 갱신되지 않으므로,
     * 오늘 한도를 바꾸면 다음 농장일 06:00~전량대조까지 낡은 한도가 집행된다(미래 행은 used=0 이라 overflow 무영향).
     */
    private function syncTodayCounter(RewardMission $mission, string $today): void
    {
        DB::table('reward_mission_daily_counters')
            ->where('mission_id', $mission->id)->where('stat_date', '>=', $today)
            ->update([
                'daily_quota' => $mission->daily_quota,
                'slot_ratios' => $mission->slot_ratios ? json_encode($mission->slot_ratios) : null,
                'overflow_count' => DB::raw('CASE WHEN used > '.((int) $mission->daily_quota).' THEN used - '.((int) $mission->daily_quota).' ELSE overflow_count END'),
                'updated_at' => now(),
            ]);
    }

    /**
     * 당일 + 익일(농장일 축) 카운터 행 선생성 — 참여 경로에서 firstOrCreate 를 부르지 않기 위한
     * 사전 확보(design-01 §2-4). insertOrIgnore 라 몇 번을 불러도 안전하다.
     */
    public function ensureDailyCounters(): int
    {
        $today = RewardDay::current();
        $dates = [$today, Carbon::parse($today)->addDay()->toDateString()];

        $missions = RewardMission::query()
            ->whereIn('status', ['draft', 'active', 'paused'])
            ->whereDate('ends_on', '>=', $today)
            ->get(['id', 'daily_quota', 'slot_ratios', 'starts_on', 'ends_on']);

        $inserted = 0;
        foreach ($missions as $mission) {
            foreach ($dates as $date) {
                if ($date < $mission->starts_on->toDateString() || $date > $mission->ends_on->toDateString()) {
                    continue;   // 노출 기간 밖 날짜는 만들지 않는다
                }
                $inserted += DB::table('reward_mission_daily_counters')->insertOrIgnore([
                    'mission_id' => $mission->id,
                    'stat_date' => $date,
                    'daily_quota' => $mission->daily_quota,
                    'slot_ratios' => $mission->slot_ratios ? json_encode($mission->slot_ratios) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return $inserted;
    }
}
