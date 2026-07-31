<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 리워드 참여자(design-01 §2-1) — 매체 스코프. x-user-key 의 sha256 해시로 식별하고 평문은 저장하지 않는다.
 * 일 한도·쿨다운·포인트 상한 판정은 이 행의 원자 UPDATE 한 문장으로 한다(Phase 3).
 */
class RewardUser extends Model
{
    protected $fillable = [
        'media_id', 'user_key_hash', 'key_type', 'anon_key_enc', 'status',
    ];

    protected $casts = [
        'anon_key_enc' => 'encrypted',
        'today_date' => 'date',
        'cooldown_until' => 'datetime',
        'last_submit_at' => 'datetime',
        'last_participated_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'cooldown_notify' => 'boolean',
    ];

    public function media(): BelongsTo
    {
        return $this->belongsTo(RewardMedia::class, 'media_id');
    }
}
