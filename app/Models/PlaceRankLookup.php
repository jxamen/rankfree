<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 순위 조회 로그 (1회성 무료 조회 + 회원 조회 공용).
 */
class PlaceRankLookup extends Model
{
    protected $fillable = [
        'user_id', 'keyword', 'place_id', 'category', 'rank', 'list_total',
        'review_count', 'blog_review_count', 'save_count', 'review_score',
        'place_name', 'ip',
    ];

    protected $casts = [
        'rank' => 'integer',
        'list_total' => 'integer',
        'review_count' => 'integer',
        'blog_review_count' => 'integer',
        'save_count' => 'integer',
        'review_score' => 'decimal:2',
    ];
}
