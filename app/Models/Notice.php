<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** 공지사항. */
class Notice extends Model
{
    protected $fillable = [
        'category', 'title', 'body', 'is_pinned', 'is_published', 'published_at', 'views',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'views' => 'integer',
    ];

    public const CATEGORIES = ['일반', '업데이트', '점검', '이벤트'];

    /** 게시 상태 + 게시일 도래분만. */
    public function scopeVisible(Builder $q): Builder
    {
        return $q->where('is_published', true)
            ->where(fn ($w) => $w->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    /** 목록 정렬 — 고정 먼저, 그 다음 최신. */
    public function scopeListed(Builder $q): Builder
    {
        return $q->orderByDesc('is_pinned')->orderByDesc('published_at')->orderByDesc('id');
    }
}
