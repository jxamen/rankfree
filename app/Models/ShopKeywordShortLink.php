<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopKeywordShortLink extends Model
{
    protected $fillable = [
        'analysis_id', 'token', 'domain', 'group_no', 'group_count', 'keywords', 'reference_keywords',
        'cursor', 'hit_count', 'last_served_at',
    ];

    protected $casts = [
        'group_no' => 'integer',
        'group_count' => 'integer',
        'keywords' => 'array',
        'reference_keywords' => 'array',
        'cursor' => 'integer',
        'hit_count' => 'integer',
        'last_served_at' => 'datetime',
    ];

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(ShopKeywordAnalysis::class, 'analysis_id');
    }

    /** 공개 Short URL — 커스텀 도메인 우선(관리 화면·발주 자동 채움 공용). */
    public function url(): string
    {
        return $this->domain
            ? 'https://'.$this->domain.'/s/'.$this->token
            : route('shop-keyword.short', $this->token);
    }
}
