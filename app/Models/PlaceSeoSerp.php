<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 경쟁분석 일별 경쟁셋 순위 스냅샷(T1). */
class PlaceSeoSerp extends Model
{
    public $timestamps = false;

    protected $table = 'place_seo_serp';

    protected $fillable = [
        'slot_id', 'ymd', 'rnk', 'place_id', 'name', 'visitor_cnt', 'blog_cnt',
        'booking_cnt', 'save_cnt', 'review_score', 'tags', 'address', 'is_mine', 'list_total', 'created_at',
    ];

    protected $casts = [
        'ymd' => 'date:Y-m-d',
        'tags' => 'array',
        'is_mine' => 'boolean',
    ];
}
