<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 서치 콘솔 검색 성과 — 일별 × 차원(date 합계 · query · page · device). */
class GscStat extends Model
{
    protected $fillable = ['date', 'dimension', 'value', 'clicks', 'impressions', 'ctr', 'position'];

    protected $casts = [
        'date' => 'date',
        'clicks' => 'integer', 'impressions' => 'integer',
        'ctr' => 'float', 'position' => 'float',
    ];
}
