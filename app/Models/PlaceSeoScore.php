<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 경쟁분석 일별 점수(시계열). */
class PlaceSeoScore extends Model
{
    public $timestamps = false;

    protected $table = 'place_seo_scores';

    protected $fillable = [
        'slot_id', 'place_id', 'ymd', 'rnk',
        'd1', 'd2', 'd3', 'd4', 'd5', 'd7', 'd8', 'd9', 'd10', 'n1', 'n2', 'n3',
        'avail_mask', 'tier', 'is_mine', 'created_at',
    ];

    protected $casts = [
        'ymd' => 'date:Y-m-d',
        'is_mine' => 'boolean',
    ];
}
