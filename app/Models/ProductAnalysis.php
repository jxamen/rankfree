<?php

namespace App\Models;

use App\Models\Concerns\HasShareSlug;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** 스마트스토어 상품 분석(리뷰 분석) 저장본. */
#[Fillable([
    'slug', 'user_id', 'origin_product_no', 'merchant_no', 'name', 'url', 'store',
    'total_reviews', 'analyzed_reviews', 'avg_score', 'repurchase_pct',
    'recent_7d', 'recent_1m', 'recent_3m', 'sales_6m', 'price', 'snapshot', 'report_html', 'share_token',
])]
class ProductAnalysis extends Model
{
    use HasShareSlug;

    protected function casts(): array
    {
        return [
            'avg_score' => 'float',
            'repurchase_pct' => 'float',
            'snapshot' => 'array',
        ];
    }

    public function shareSlugBasis(): string
    {
        return (string) $this->name;
    }

    public function shareSlugPrefix(): string
    {
        return 'product';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
