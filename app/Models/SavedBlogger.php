<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 저장 블로거 — 키워드 분석 결과에서 찜한 블로거(키워드 × blog_id, 저장 시점 스냅샷). */
class SavedBlogger extends Model
{
    protected $fillable = [
        'user_id', 'keyword', 'blog_id', 'blog_name', 'score', 'grade', 'data',
    ];

    protected $casts = [
        'data' => 'array',
        'score' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
