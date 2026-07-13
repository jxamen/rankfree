<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** 키워드 분석 검색 내역 — 사용자별 최근 검색(재조회용). 같은 키워드는 갱신. */
class KeywordSearch extends Model
{
    protected $fillable = [
        'user_id', 'keyword', 'monthly_total', 'monthly_pc', 'monthly_mobile', 'comp_idx', 'grade', 'share_token', 'snapshot',
    ];

    protected $casts = [
        'monthly_total' => 'integer',
        'monthly_pc' => 'integer',
        'monthly_mobile' => 'integer',
        'snapshot' => 'array',
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
