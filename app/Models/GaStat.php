<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** GA4 방문 통계 — 일별 × 차원(date 합계 · channel · source · page). */
class GaStat extends Model
{
    protected $fillable = ['date', 'dimension', 'value', 'users', 'new_users', 'sessions', 'pageviews'];

    protected $casts = [
        'date' => 'date',
        'users' => 'integer', 'new_users' => 'integer', 'sessions' => 'integer', 'pageviews' => 'integer',
    ];
}
