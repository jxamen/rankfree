<?php

namespace App\Models;

use App\Models\Concerns\HasShareSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * 키워드 분석 검색 내역(origin=user, 사용자별 갱신) + 허브 발행 문서(origin=hub, user_id NULL·시스템 소유).
 * 허브 문서 유일성은 (origin=hub, keyword) 로 코드에서 보장한다(22_KEYWORD_CONTENT_HUB).
 */
class KeywordSearch extends Model
{
    use HasShareSlug;

    protected $fillable = [
        'slug', 'user_id', 'category_id', 'region', 'region_type', 'origin', 'keyword', 'monthly_total', 'monthly_pc', 'monthly_mobile',
        'comp_idx', 'grade', 'share_token', 'snapshot', 'refreshed_at',
    ];

    public function shareSlugBasis(): string
    {
        return (string) $this->keyword;
    }

    public function shareSlugPrefix(): string
    {
        return 'keyword';
    }

    protected $casts = [
        'monthly_total' => 'integer',
        'monthly_pc' => 'integer',
        'monthly_mobile' => 'integer',
        'snapshot' => 'array',
        'refreshed_at' => 'datetime',
    ];

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
