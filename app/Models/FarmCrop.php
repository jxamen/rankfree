<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 작물 마스터(design-01 §2-2) — 퀴즈농장 게임 도메인. points 는 수확 보너스(심을 때 스냅샷, 소급 없음). */
class FarmCrop extends Model
{
    protected $fillable = ['code', 'name', 'emoji', 'days', 'points', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
