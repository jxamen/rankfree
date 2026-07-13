<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 키워드 대량 분석 — 키워드 1개(행)의 상태·수집 데이터. */
class BulkKeywordItem extends Model
{
    protected $fillable = ['bulk_keyword_id', 'keyword', 'status', 'fail_reason', 'data', 'sort'];

    protected $casts = ['data' => 'array'];

    public function bulk(): BelongsTo
    {
        return $this->belongsTo(BulkKeyword::class, 'bulk_keyword_id');
    }
}
