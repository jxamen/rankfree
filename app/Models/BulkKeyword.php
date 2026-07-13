<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 키워드 대량 분석 요청(batch). */
class BulkKeyword extends Model
{
    protected $fillable = ['user_id', 'name', 'status', 'total', 'done', 'failed', 'include_serp', 'finished_at'];

    protected $casts = ['include_serp' => 'boolean', 'finished_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BulkKeywordItem::class);
    }

    public function progressPct(): int
    {
        return $this->total > 0 ? (int) round(($this->done + $this->failed) / $this->total * 100) : 0;
    }
}
