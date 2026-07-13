<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 쇼핑 순위추적 슬롯 = (키워드 × 대상 상품/업체). place_rank_slots 미러. */
class ShopRankSlot extends Model
{
    protected $fillable = [
        'user_id', 'keyword', 'category', 'monthly_views', 'target_type', 'product_id', 'mall_name', 'product_url',
        'product_title', 'label', 'share_token', 'is_active', 'last_rank', 'last_price', 'last_checked_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_checked_at' => 'datetime',
        'last_rank' => 'integer',
        'last_price' => 'integer',
        'monthly_views' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(ShopRankRecord::class, 'slot_id')->orderBy('checked_date');
    }
}
