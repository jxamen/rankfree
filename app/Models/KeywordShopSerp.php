<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 키워드별 쇼핑 노출 상품 스냅샷(상위 80개) — 확장이 수집해 저장한다(서버는 418 차단).
 */
class KeywordShopSerp extends Model
{
    protected $fillable = ['keyword', 'total', 'item_count', 'items', 'related_tags', 'collected_at'];

    protected $casts = [
        'items' => 'array',
        'related_tags' => 'array',
        'total' => 'integer',
        'item_count' => 'integer',
        'collected_at' => 'datetime',
    ];
}
