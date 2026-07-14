<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 플레이스 매장 분석(정밀 N1/N2/N3 + D지표) 저장본 — 확장 매장분석 탭. */
#[Fillable([
    'user_id', 'place_id', 'name', 'keyword', 'cat', 'rank',
    'n1', 'n2', 'n3', 'visitor_cnt', 'blog_cnt', 'save_cnt', 'detail',
])]
class PlaceStoreAnalysis extends Model
{
    protected function casts(): array
    {
        return [
            'n1' => 'float',
            'n2' => 'float',
            'n3' => 'float',
            'detail' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
