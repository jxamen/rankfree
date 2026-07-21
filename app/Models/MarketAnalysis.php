<?php

namespace App\Models;

use App\Models\Concerns\HasShareSlug;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** 쇼핑 시장 분석 저장본 — 확장 프로그램이 수집·계산한 결과. */
#[Fillable([
    'slug', 'user_id', 'category_id', 'keyword', 'total_count', 'item_count', 'include_ads',
    'sales_6m', 'revenue_6m', 'avg_price', 'median_price', 'top10_share',
    'monthly_search', 'comp_idx', 'snapshot', 'share_token',
])]
class MarketAnalysis extends Model
{
    use HasShareSlug;

    protected function casts(): array
    {
        return [
            'include_ads' => 'boolean',
            'top10_share' => 'float',
            'snapshot' => 'array',
        ];
    }

    public function shareSlugBasis(): string
    {
        return (string) $this->keyword;
    }

    public function shareSlugPrefix(): string
    {
        return 'market';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(KeywordCategory::class, 'category_id');
    }

    /** 공개 공유 토큰 — 없으면 생성해 저장. */
    public function shareToken(): string
    {
        if (! $this->share_token) {
            $this->forceFill(['share_token' => Str::random(32)])->save();
        }

        return $this->share_token;
    }
}
