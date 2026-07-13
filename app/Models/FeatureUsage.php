<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 회원별 기능 사용량(월간 집계). */
#[Fillable(['user_id', 'feature', 'period', 'count'])]
class FeatureUsage extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
