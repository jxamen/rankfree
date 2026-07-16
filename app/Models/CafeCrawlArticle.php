<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 카페 글감 수집 원본(글) — cafe:crawl 이 저장. seed_id 로 글밥(community_seeds) 전환 추적. */
class CafeCrawlArticle extends Model
{
    protected $fillable = [
        'cafe_id', 'article_id', 'title', 'body', 'writer', 'wrote_at',
        'read_count', 'comment_count', 'url', 'seed_id', 'seeded_at', 'crawled_at',
    ];

    protected $casts = [
        'wrote_at' => 'datetime',
        'seeded_at' => 'datetime',
        'crawled_at' => 'datetime',
    ];

    public function comments(): HasMany
    {
        return $this->hasMany(CafeCrawlComment::class, 'crawl_article_id');
    }

    public function seed(): BelongsTo
    {
        return $this->belongsTo(CommunitySeed::class, 'seed_id');
    }
}
