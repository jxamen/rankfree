<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 네이버 검색광고 웹 콘솔 세션 (단일 레코드 id=1). cookies 는 암호화 저장. */
class NaverAdSession extends Model
{
    protected $fillable = ['id', 'cookies', 'customer_id', 'status', 'logged_in_at', 'checked_at'];

    protected $casts = [
        'logged_in_at' => 'datetime',
        'checked_at' => 'datetime',
    ];
}
