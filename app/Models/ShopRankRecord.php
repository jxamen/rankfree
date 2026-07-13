<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 쇼핑 순위 일별 기록. place_rank_records 미러. */
class ShopRankRecord extends Model
{
    public $timestamps = false;

    protected $fillable = ['slot_id', 'rank', 'price', 'list_total', 'checked_date', 'created_at'];

    protected $casts = [
        // sqlite 에서 순수 date 캐스트는 Y-m-d H:i:s 로 저장돼 하루단위 updateOrCreate 가 깨진다 → 명시 포맷
        'checked_date' => 'date:Y-m-d',
        'created_at' => 'datetime',
        'rank' => 'integer',
        'price' => 'integer',
        'list_total' => 'integer',
    ];

    public function slot(): BelongsTo
    {
        return $this->belongsTo(ShopRankSlot::class, 'slot_id');
    }
}
