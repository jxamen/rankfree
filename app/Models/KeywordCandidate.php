<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 키워드 콘텐츠 허브 — 키워드 후보. pending → approved(관리자) → published(hub:publish). */
class KeywordCandidate extends Model
{
    public const STATUSES = ['pending', 'approved', 'rejected', 'published'];

    protected $fillable = [
        'category_id', 'region', 'region_type', 'keyword', 'source', 'monthly_total', 'comp_idx', 'volume_checked_at', 'status', 'note',
    ];

    protected $casts = [
        'monthly_total' => 'integer',
        'volume_checked_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(KeywordCategory::class, 'category_id');
    }
}
