<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 재배 회차(design-01 §2-8) — 퀴즈농장 게임 도메인 + 부채 원장.
 * reward_points(수확 보너스)는 심을 때 스냅샷·소급 금지. 성장은 day_mask 비트 원자 UPDATE 로만.
 */
class FarmPlanting extends Model
{
    protected $guarded = [];

    protected $casts = [
        'planted_on' => 'date',
        'last_tended_on' => 'date',
        'harvested_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(RewardUser::class, 'reward_user_id');
    }
}
