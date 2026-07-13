<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 블로그 지수 분석 이력 — 사용자별 스냅샷(키워드→블로거 목록 / 블로그ID→단건). */
class BlogIndexAnalysis extends Model
{
    protected $fillable = [
        'user_id', 'type', 'query', 'title', 'score', 'grade', 'blogger_count', 'snapshot',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'score' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
