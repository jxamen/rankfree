<?php

namespace App\Domain\Place;

use App\Models\PlaceRankRecord;
use App\Models\PlaceRankSlot;
use App\Models\User;
use DomainException;

/** 순위 추적 슬롯 도메인 로직 — 웹 콘솔·배치·API 가 공유. */
class RankSlotService
{
    /**
     * 순위 밖 센티넬 — 플레이스는 미노출을 **300** 으로 기록한다(PlaceRankChecker: `$found ? $rank : 300`).
     * 쇼핑은 0 을 쓴다. 자동 중단 판정이 이 차이를 놓치면 영원히 중단되지 않는다.
     */
    public const RANK_OUT = 300;

    public function __construct(private PlaceRankChecker $checker) {}

    /** 순위 밖인가 — 300(미노출) 또는 0(대상 미확정 등 조회 불가). */
    private static function isOut(int $rank): bool
    {
        return $rank === 0 || $rank >= self::RANK_OUT;
    }

    /**
     * 입력(URL·단축URL·ID·업체명) → 플레이스 메타 확정. 업체명·카테고리 자동조회.
     * crm sp_place_url_convert 방식: pid 추출 → 업종 판별 → m.place.naver.com/{업종}/{pid} 정규 URL.
     *
     * @return array{place_id:?string, place_name:?string, place_url:?string, category:?string}
     */
    public function resolvePlace(string $input): array
    {
        $input = trim($input);
        $isUrl = (bool) preg_match('#^https?://#i', $input) || str_contains(strtolower($input), 'naver.');
        $placeId = $this->checker->resolvePlaceId($input);

        $out = [
            'place_id' => $placeId,
            'place_name' => null,
            'place_url' => $isUrl ? $input : null,
            'category' => null,
        ];

        if ($placeId) {
            $meta = $this->checker->placeSummary($placeId);
            $cat = $meta['category'] !== '' ? $meta['category'] : 'place';
            $out['category'] = $cat;
            $out['place_name'] = $meta['name'] !== '' ? $meta['name'] : null;
            // 저장 URL 은 표준(place/{id}/home) 단일 소스 — 업종(category)은 URL 에 쓰지 않는다(hairshop 등 오저장 방지)
            $out['place_url'] = PlaceRankChecker::mobilePlaceUrl($placeId);
        } elseif (! $isUrl) {
            // URL 이 아니면 업체명 직접 입력으로 간주
            $out['place_name'] = $input;
        }

        return $out;
    }

    /**
     * URL/ID 1개 + 키워드 N개 → 슬롯 N개(키워드별 1슬롯). 업체명 1회 자동조회 후 공유.
     * 한도 초과 시 등록 전 DomainException. 중복 키워드는 조용히 건너뜀.
     *
     * @param  string[]  $keywords
     * @return array{place:array, created:list<PlaceRankSlot>, skipped:list<string>}
     *
     * @throws DomainException
     */
    public function addMany(User $user, string $placeInput, array $keywords, ?string $label = null, bool $ignoreLimit = false): array
    {
        $keywords = array_values(array_unique(array_filter(
            array_map('trim', $keywords),
            fn ($k) => $k !== '',
        )));
        if (! count($keywords)) {
            throw new DomainException('추적할 키워드를 1개 이상 입력하세요.');
        }

        $lim = $user->rankSlotLimit();
        $used = $user->rankSlotsUsedTotal(); // 플레이스+쇼핑 합산(공유 풀)
        if (! $ignoreLimit && $lim >= 0 && $used + count($keywords) > $lim) {
            $room = max(0, $lim - $used);
            throw new DomainException("추적 한도({$lim}개, 플레이스+쇼핑 합산)를 초과합니다. 현재 {$used}개 사용 중 · 추가 가능 {$room}개(요청 ".count($keywords).'개).');
        }

        $place = $this->resolvePlace($placeInput);

        $created = [];
        $skipped = [];
        foreach ($keywords as $kw) {
            $dupe = $user->rankSlots()
                ->where('keyword', $kw)
                ->when(
                    $place['place_id'],
                    fn ($q) => $q->where('place_id', $place['place_id']),
                    fn ($q) => $q->where('place_name', $place['place_name']),
                )
                ->exists();
            if ($dupe) {
                $skipped[] = $kw;

                continue;
            }

            $created[] = $user->rankSlots()->create([
                'keyword' => $kw,
                'place_id' => $place['place_id'],
                'place_name' => $place['place_name'],
                'place_url' => $place['place_url'],
                'category' => $place['category'] ?: 'place',
                'label' => $label,
                'share_token' => \Illuminate\Support\Str::random(32),
                'is_active' => true,
            ]);
        }

        return ['place' => $place, 'created' => $created, 'skipped' => $skipped];
    }

    /**
     * 슬롯 1개 추가(키워드 1 × 플레이스 1). addMany 의 단건 래퍼 — API 단건 호출 호환용.
     *
     * @throws DomainException 한도 초과·중복 시
     */
    public function add(User $user, string $keyword, string $place, ?string $label = null): PlaceRankSlot
    {
        $res = $this->addMany($user, $place, [$keyword], $label);
        if (! count($res['created'])) {
            throw new DomainException('이미 추적 중인 키워드 × 플레이스입니다.');
        }

        return $res['created'][0];
    }

    /** 즉시 1회 순위 조회 + 오늘 기록(1일 1레코드, 있으면 갱신) + 슬롯 최신값 갱신. @return array 조회 결과 */
    public function run(PlaceRankSlot $slot): array
    {
        $r = $this->checker->check($slot->keyword, $slot->place_id, $slot->place_name, (string) $slot->category);

        // 차단(토큰 만료·429) 미발견 결과는 기록하지 않는다 — rank 0 이 '300+' 로 잘못 남거나
        // 당일의 유효 기록을 덮는 것을 방지. 확인 시각만 남긴다.
        if ($r['blocked'] && ! $r['found']) {
            $slot->update(['last_checked_at' => now()]);

            return $r;
        }

        // 슬롯당 (slot_id, checked_date) 유니크 — 당일 기록이 있으면 update, 없으면 create.
        PlaceRankRecord::updateOrCreate(
            ['slot_id' => $slot->id, 'checked_date' => now()->toDateString()],
            [
                'rank' => $r['rank'],
                'review_count' => $r['review_count'],
                'blog_review_count' => $r['blog_review_count'],
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

        // 3일 연속 순위 밖이면 자동 중단(2026-07-24) — 트래픽 부담만 늘어 체크 중지.
        // 삭제 아님 — 목록 [재개] 버튼으로 다시 켤 수 있다.
        // ⚠️ 0 만 보면 안 된다 — 플레이스 미노출은 300 으로 기록되므로 중단이 한 번도 걸리지 않았다
        //    (실측 2026-08-05: 최근 7일 미노출 기록 196건, rank=0 은 0건, 중단된 슬롯 0개).
        // 차단(토큰 만료·429)은 위에서 기록 자체를 건너뛰므로 판정에 섞이지 않는다.
        if (self::isOut((int) $r['rank']) && $slot->is_active) {
            $recent = PlaceRankRecord::where('slot_id', $slot->id)
                ->orderByDesc('checked_date')->limit(3)->pluck('rank');
            if ($recent->count() === 3 && $recent->every(fn ($v) => self::isOut((int) $v))) {
                $slot->update(['is_active' => false]);
            }
        }

        return $r;
    }
}
