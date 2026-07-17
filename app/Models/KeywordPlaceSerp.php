<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 키워드별 플레이스 노출 업체 스냅샷 — 한 번 수집해 저장하고 '다시 수집' 때만 갱신한다.
 * 순위·리뷰수는 변동이 크지 않아 매번 재수집(17초)할 이유가 없다.
 */
class KeywordPlaceSerp extends Model
{
    protected $fillable = ['keyword', 'cat', 'total', 'item_count', 'items', 'collected_at'];

    protected $casts = [
        'items' => 'array',
        'total' => 'integer',
        'item_count' => 'integer',
        'collected_at' => 'datetime',
    ];
}
