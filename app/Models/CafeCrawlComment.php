<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 카페 글감 수집 원본(댓글). seed_id 로 글밥(community_seeds) 전환 추적. */
class CafeCrawlComment extends Model
{
    protected $fillable = [
        'crawl_article_id', 'comment_id', 'parent_comment_id', 'writer',
        'content', 'wrote_at', 'is_deleted', 'seed_id', 'seeded_at',
    ];

    protected $casts = [
        'wrote_at' => 'datetime',
        'is_deleted' => 'boolean',
        'seeded_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(CafeCrawlArticle::class, 'crawl_article_id');
    }

    public function seed(): BelongsTo
    {
        return $this->belongsTo(CommunitySeed::class, 'seed_id');
    }
}
