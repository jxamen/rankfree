<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 경쟁분석 일별 플레이스 상세신호(place 단위 공유). */
class PlaceSeoDaily extends Model
{
    public $timestamps = false;

    protected $table = 'place_seo_daily';

    protected $fillable = [
        'place_id', 'ymd', 'name', 'category', 'visitor_cnt', 'blog_cnt', 'booking_cnt', 'save_cnt', 'review_score',
        'menu_cnt', 'photo_cnt', 'conv_cnt', 'pay_cnt', 'keyword_cnt', 'category_cnt', 'stylist_cnt',
        'has_road', 'has_talktalk', 'has_chatbot', 'has_booking', 'hide_hours', 'hide_price',
        'missing_cnt', 'missing_labels', 'place_plus', 'tags', 'review_kw', 'created_at',
    ];

    protected $casts = [
        'ymd' => 'date:Y-m-d',
        'tags' => 'array',
        'review_kw' => 'array',
    ];
}
