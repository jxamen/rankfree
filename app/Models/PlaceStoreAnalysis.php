<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** 플레이스 매장 분석(정밀 N1/N2/N3 + D지표) 저장본 — 확장 매장분석 탭. */
#[Fillable([
    'user_id', 'place_id', 'name', 'keyword', 'cat', 'rank',
    'n1', 'n2', 'n3', 'visitor_cnt', 'blog_cnt', 'save_cnt', 'detail', 'share_token',
])]
class PlaceStoreAnalysis extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (empty($m->share_token)) {
                $m->share_token = Str::random(32);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'n1' => 'float',
            'n2' => 'float',
            'n3' => 'float',
            'detail' => 'array',
        ];
    }

    /** 비로그인 공개 공유 URL */
    public function shareUrl(): string
    {
        return url('/ps/'.$this->share_token);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
