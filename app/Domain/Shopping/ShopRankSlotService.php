<?php

namespace App\Domain\Shopping;

use App\Models\ShopRankRecord;
use App\Models\ShopRankSlot;
use App\Models\User;
use DomainException;
use Illuminate\Support\Str;

/**
 * 쇼핑 순위추적 슬롯 오케스트레이션 — Place\RankSlotService 미러.
 * 대상(상품 URL/업체명) × 키워드 슬롯 생성·조회·일별 순위 실행.
 */
class ShopRankSlotService
{
    /** 차단(429 로 전 키 소진) 시 기록 rank 센티널. */
    public const RANK_BLOCKED = -1;

    public function __construct(private NaverShoppingRankService $engine) {}

    /** 상품 URL/업체명 입력 → 대상 파싱(미리보기·저장용). */
    public function resolve(string $input): array
    {
        return $this->engine->resolveTarget($input);
    }

    /**
     * 대상 1개 × 키워드 N개 → 슬롯 N개. 한도 검사 · 중복 스킵.
     *
     * @return array{target:array, created:list<ShopRankSlot>, skipped:list<string>}
     */
    public function addMany(User $user, string $targetInput, array $keywords, ?string $label = null): array
    {
        $keywords = collect($keywords)->map(fn ($k) => trim((string) $k))->filter()->unique()->values();
        if ($keywords->isEmpty()) {
            throw new DomainException('키워드를 하나 이상 입력하세요.');
        }

        $limit = $user->rankSlotLimit();
        $used = $user->rankSlotsUsedTotal(); // 플레이스+쇼핑 합산(공유 풀)
        if ($limit >= 0 && $used + $keywords->count() > $limit) {
            throw new DomainException("슬롯 한도를 초과합니다 (사용 {$used} / 한도 {$limit}, 플레이스+쇼핑 합산).");
        }

        $target = $this->engine->resolveTarget($targetInput);
        if ($target['product_id'] === '' && $target['mall_name'] === '') {
            throw new DomainException('상품 URL(스마트스토어/가격비교) 또는 업체명을 확인하세요.');
        }

        $created = [];
        $skipped = [];
        foreach ($keywords as $kw) {
            $dup = ShopRankSlot::where('user_id', $user->id)->where('keyword', $kw)
                ->where(fn ($q) => $target['product_id'] !== ''
                    ? $q->where('product_id', $target['product_id'])
                    : $q->where('mall_name', $target['mall_name']))
                ->exists();
            if ($dup) {
                $skipped[] = $kw;

                continue;
            }
            $created[] = ShopRankSlot::create([
                'user_id' => $user->id,
                'keyword' => $kw,
                'target_type' => $target['type'],
                'product_id' => $target['product_id'] ?: null,
                'mall_name' => $target['mall_name'] ?: null,
                'product_url' => $target['url'] ?: null,
                'label' => $label ?: null,
                'share_token' => Str::random(32),
                'is_active' => true,
            ]);
        }

        return ['target' => $target, 'created' => $created, 'skipped' => $skipped];
    }

    /** 단건 추가(API 호환). 중복이면 예외. */
    public function add(User $user, string $keyword, string $target, ?string $label = null): ShopRankSlot
    {
        $r = $this->addMany($user, $target, [$keyword], $label);
        if (! count($r['created'])) {
            throw new DomainException('이미 추적 중인 키워드입니다.');
        }

        return $r['created'][0];
    }

    /** 슬롯 1개 실시간 순위 조회 + 일별 기록 저장(멱등). */
    public function run(ShopRankSlot $slot): array
    {
        $res = $this->engine->checkRank($slot->keyword, [
            'type' => $slot->target_type,
            'product_id' => (string) $slot->product_id,
            'mall_name' => (string) $slot->mall_name,
            'url' => (string) $slot->product_url,
        ]);

        $rank = ($res['blocked'] && ! $res['found']) ? self::RANK_BLOCKED : (int) $res['rank'];

        // 차단(전 키 429)이라도 오늘 이미 유효 순위가 기록돼 있으면 -1 로 덮지 않는다 —
        // 매시간 재확인 중 한도가 소진돼도 그날의 정상 데이터를 보존.
        if ($rank === self::RANK_BLOCKED) {
            $kept = ShopRankRecord::where('slot_id', $slot->id)
                ->where('checked_date', now()->toDateString())
                ->where('rank', '>', 0)->first();
            if ($kept) {
                $slot->last_checked_at = now();
                $slot->save();

                return $res + ['stored_rank' => (int) $kept->rank];
            }
        }

        ShopRankRecord::updateOrCreate(
            ['slot_id' => $slot->id, 'checked_date' => now()->toDateString()],
            ['rank' => $rank, 'price' => $res['price'] ?: null, 'list_total' => (int) ($res['total'] ?? 0), 'created_at' => now()],
        );

        $slot->last_rank = $rank;
        $slot->last_price = $res['price'] ?: null;
        $slot->last_checked_at = now();
        if (($res['product_id'] ?? '') !== '' && ! $slot->product_id) {
            $slot->product_id = $res['product_id'];
        }
        if (($res['title'] ?? '') !== '' && ! $slot->product_title) {
            $slot->product_title = $res['title'];
        }
        if (($res['mall_name'] ?? '') !== '' && ! $slot->mall_name) {
            $slot->mall_name = $res['mall_name'];
        }
        $slot->save();

        return $res + ['stored_rank' => $rank];
    }
}
