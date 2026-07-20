<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 쇼핑 노출 키워드 분석 1회 — 핵심 키워드 + 상품 대상 + 조합 순위체크 요약(25). */
class ShopKeywordAnalysis extends Model
{
    protected $fillable = [
        'user_id', 'core_keyword', 'product_url', 'product_id', 'mall_name', 'product_title',
        'threshold', 'token_count', 'combo_count', 'checked_count', 'exposed_count', 'status',
    ];

    protected $casts = [
        'threshold' => 'integer',
        'token_count' => 'integer',
        'combo_count' => 'integer',
        'checked_count' => 'integer',
        'exposed_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShopKeywordAnalysisItem::class, 'analysis_id');
    }

    public function tokens(): HasMany
    {
        return $this->items()->where('kind', 'token');
    }

    public function combos(): HasMany
    {
        return $this->items()->where('kind', 'combo');
    }

    /** 노출(1~threshold위) 조합만 — 순위 오름차순. */
    public function exposed(): HasMany
    {
        return $this->combos()->whereBetween('rank', [1, $this->threshold])->orderBy('rank');
    }
}
