<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * nCaptcha 토큰 (세션 범용 단일 레코드, id=1). 순위 조회 pcmap-api 200 필수.
 */
class PlaceRankToken extends Model
{
    public $timestamps = false;

    protected $fillable = ['id', 'token', 'updated_at'];

    protected $casts = ['updated_at' => 'datetime'];
}
