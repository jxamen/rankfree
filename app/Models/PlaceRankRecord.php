<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 순위 추적 슬롯의 일자별 기록 (하루 1건). */
class PlaceRankRecord extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'slot_id', 'rank', 'review_count', 'save_count', 'review_score', 'list_total', 'checked_date', 'created_at',
    ];

    protected $casts = [
        'checked_date' => 'date',
        'created_at' => 'datetime',
        'rank' => 'integer',
        'list_total' => 'integer',
    ];

    public function slot(): BelongsTo
    {
        return $this->belongsTo(PlaceRankSlot::class, 'slot_id');
    }
}
