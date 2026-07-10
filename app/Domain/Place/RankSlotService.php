<?php

namespace App\Domain\Place;

use App\Models\PlaceRankRecord;
use App\Models\PlaceRankSlot;
use App\Models\User;
use DomainException;

/** 순위 추적 슬롯 도메인 로직 — 웹 콘솔·배치·API 가 공유. */
class RankSlotService
{
    public function __construct(private PlaceRankChecker $checker) {}

    /**
     * 슬롯 추가. 한도 초과·중복이면 DomainException.
     *
     * @throws DomainException
     */
    public function add(User $user, string $keyword, string $place, ?string $label = null): PlaceRankSlot
    {
        if (! $user->canAddRankSlot()) {
            $lim = $user->rankSlotLimit();
            throw new DomainException('무료 추적 한도('.($lim < 0 ? '무제한' : $lim.'개').')를 초과했습니다. 요금제를 올려주세요.');
        }

        $keyword = trim($keyword);
        $place = trim($place);
        $placeId = PlaceRankChecker::extractPlaceId($place);
        $isUrl = str_contains(strtolower($place), 'naver.');

        $dupe = $user->rankSlots()
            ->where('keyword', $keyword)
            ->when($placeId, fn ($q) => $q->where('place_id', $placeId), fn ($q) => $q->where('place_name', $place))
            ->exists();
        if ($dupe) {
            throw new DomainException('이미 추적 중인 키워드 × 플레이스입니다.');
        }

        return $user->rankSlots()->create([
            'keyword' => $keyword,
            'place_id' => $placeId,
            'place_name' => $placeId ? null : $place,
            'place_url' => $isUrl ? $place : null,
            'label' => $label,
            'is_active' => true,
        ]);
    }

    /** 즉시 1회 순위 조회 + 오늘 기록 + 슬롯 최신값 갱신. @return array 조회 결과 */
    public function run(PlaceRankSlot $slot): array
    {
        $r = $this->checker->check($slot->keyword, $slot->place_id, $slot->place_name, (string) $slot->category);

        PlaceRankRecord::updateOrCreate(
            ['slot_id' => $slot->id, 'checked_date' => now()->toDateString()],
            [
                'rank' => $r['rank'],
                'review_count' => $r['review_count'],
                'save_count' => $r['save_count'],
                'review_score' => $r['review_score'],
                'list_total' => $r['list_total'],
                'created_at' => now(),
            ],
        );

        $slot->update([
            'place_id' => $slot->place_id ?: ($r['place_id'] ?: null),
            'place_name' => $slot->place_name ?: ($r['place_name'] ?: null),
            'category' => $r['category'] ?: $slot->category,
            'last_rank' => $r['rank'],
            'last_review_count' => $r['review_count'],
            'last_checked_at' => now(),
        ]);

        return $r;
    }
}
