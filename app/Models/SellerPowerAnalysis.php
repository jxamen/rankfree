<?php

namespace App\Models;

use App\Models\Concerns\HasShareSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** 셀러력 분석 결과 스냅샷 — 확장 수집분을 서버가 계산해 저장. */
class SellerPowerAnalysis extends Model
{
    use HasShareSlug;

    protected $fillable = [
        'slug', 'user_id', 'keyword', 'product_url', 'product_name', 'store_id',
        'score', 'grade', 'market_percentile', 'rank_in_top', 'competitor_count', 'snapshot', 'share_token',
    ];

    public function shareSlugBasis(): string
    {
        return (string) ($this->product_name ?: $this->keyword);
    }

    public function shareSlugPrefix(): string
    {
        return 'seller';
    }

    protected $casts = [
        'snapshot' => 'array',
        'score' => 'float',
        'market_percentile' => 'integer',
        'rank_in_top' => 'integer',
        'competitor_count' => 'integer',
    ];

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
