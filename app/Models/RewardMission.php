<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 리워드 미션(design-01 §2-3, C10 확정 컬럼명) — 세부주문서 미러 + 미션 마스터.
 * answer 는 API 응답에 절대 포함하지 않는다($hidden). 동기화는 MissionSync 가 담당.
 */
class RewardMission extends Model
{
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
