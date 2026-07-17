<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 키워드 마스터 — 목록·정렬·페이징 전용(키워드 유니크).
 * keyword_candidates 는 '키워드 × 분류' 매핑이라 키워드 단위 정렬이 풀스캔이 된다(99만 행·18.5초).
 * 이 테이블은 고유 키워드(26.8만)만 담고 (type, monthly_total)·(type, serp_collected_at) 인덱스로 즉답한다.
 */
class Keyword extends Model
{
    protected $fillable = [
        'keyword', 'type', 'region', 'region_type', 'source', 'monthly_total', 'comp_idx',
        'volume_checked_at', 'serp_collected_at', 'serp_count', 'cat_cnt', 'category_id',
    ];

    protected $casts = [
        'monthly_total' => 'integer',
        'serp_count' => 'integer',
        'cat_cnt' => 'integer',
        'volume_checked_at' => 'datetime',
        'serp_collected_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(KeywordCategory::class, 'category_id');
    }
}
