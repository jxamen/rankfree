<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 리워드 미션(design-01 §2-3, C10 확정 컬럼명) — 세부주문서 미러 + 미션 마스터.
 * answer 는 API 응답에 절대 포함하지 않는다($hidden). 동기화는 MissionSync 가 담당.
 */
class RewardMission extends Model
{
    /**
     * 미션 유형(대분류) — design-05 §2. 매체는 이 키로 원하는 유형만 골라 받는다(2026-08-28).
     * 세부 로직(저장·찜 등)은 variant 축이며 아직 미구현이다.
     */
    /**
     * 유형 값 정규화(2026-08-28) — 유형 축 승격(design-05 §2) **이전 데이터는 `external`** 이라
     * 그대로 두면 유형 필터에 아무것도 걸리지 않는다. 모르는 값은 쇼핑으로 본다(동기화되는 미션이 전부 쇼핑).
     */
    public static function normalizeKind(?string $kind): string
    {
        $k = trim((string) $kind);

        return isset(self::KINDS[$k]) ? $k : 'shopping';
    }

    public const KINDS = [
        'shopping' => '쇼핑',
        'place' => '플레이스',
        'mall' => '몰',
        'web' => '웹',
    ];

    public const STATUSES = ['draft', 'active', 'paused', 'ended', 'canceled'];

    protected $guarded = [];

    protected $hidden = ['answer', 'answer_type', 'tolerance_percent', 'tags'];   // 정답 계열 — 직렬화 사고 방지

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'slot_ratios' => 'array',
        'guide' => 'array',
        'tags' => 'array',
        'synced_at' => 'datetime',
    ];
}
