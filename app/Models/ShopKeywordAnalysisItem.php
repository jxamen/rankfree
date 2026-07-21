<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 분석 항목 — 추출 키워드(kind=token) 또는 조합 후보(kind=combo, 순위체크 대상). */
class ShopKeywordAnalysisItem extends Model
{
    protected $fillable = [
        'analysis_id', 'kind', 'source', 'keyword', 'combo_tag', 'rank', 'ad_exposed', 'hidden', 'monthly_total', 'checked_at',
    ];

    protected $casts = [
        'rank' => 'integer',
        'ad_exposed' => 'boolean',
        'hidden' => 'boolean',
        'monthly_total' => 'integer',
        'checked_at' => 'datetime',
    ];

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(ShopKeywordAnalysis::class, 'analysis_id');
    }
}
