<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 순위 추적 슬롯의 일자별 기록 (하루 1건). */
class PlaceRankRecord extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'slot_id', 'rank', 'review_count', 'blog_review_count', 'save_count', 'review_score', 'list_total', 'checked_date', 'created_at',
    ];

    protected $casts = [
        // date:Y-m-d 고정 — 'date' 캐스트는 'Y-m-d H:i:s'로 저장돼(sqlite)
        // updateOrCreate(checked_date='Y-m-d') 조회가 빗나가 유니크 위반을 일으킨다
        'checked_date' => 'date:Y-m-d',
        'created_at' => 'datetime',
        'rank' => 'integer',
        'list_total' => 'integer',
    ];

    public function slot(): BelongsTo
    {
        return $this->belongsTo(PlaceRankSlot::class, 'slot_id');
    }
}
