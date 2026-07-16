<?php

namespace App\Models;

use App\Models\Concerns\HasShareSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 순위 추적 슬롯 (키워드 × 플레이스). 무료 등급 한도 내에서 등록. */
class PlaceRankSlot extends Model
{
    use HasShareSlug;

    protected $fillable = [
        'slug', 'user_id', 'keyword', 'place_id', 'place_name', 'place_url',
        'category', 'label', 'share_token', 'is_active', 'last_rank', 'last_review_count', 'last_checked_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_checked_at' => 'datetime',
        'last_rank' => 'integer',
        'last_review_count' => 'integer',
    ];

    public function shareSlugBasis(): string
    {
        return (string) ($this->place_name ?: $this->keyword);
    }

    public function shareSlugPrefix(): string
    {
        return 'place';
    }

    /** 경쟁분석 공개 공유 URL(순위추적과 같은 슬러그, /compete 경로). */
    public function competeUrl(): string
    {
        return url('/compete/'.$this->shareSlug());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(PlaceRankRecord::class, 'slot_id')->orderBy('checked_date');
    }
}
