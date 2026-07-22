<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 쇼핑 노출 키워드 분석 1회 — 핵심 키워드 + 상품 대상 + 조합 순위체크 요약(25). */
class ShopKeywordAnalysis extends Model
{
    /** 순위 확인 방식 — api: shop.json(빠름·차단 없음) | search: 통합검색 크롤링(실화면 기준·광고 판별, 차단 가능) */
    public const CHECK_METHODS = [
        'api' => '쇼핑 API (빠름 · 차단 없음)',
        'search' => '통합검색 크롤링 (실제 화면 기준 · 광고 판별)',
    ];

    protected $fillable = [
        'user_id', 'marketing_order_id', 'core_keyword', 'product_url', 'product_id', 'mall_name', 'brand', 'product_title', 'product_price',
        'threshold', 'token_count', 'combo_count', 'checked_count', 'exposed_count', 'status', 'banned', 'check_method',
    ];

    protected $casts = [
        'threshold' => 'integer',
        'token_count' => 'integer',
        'combo_count' => 'integer',
        'checked_count' => 'integer',
        'exposed_count' => 'integer',
        'product_price' => 'integer',
        'banned' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 연결된 쇼핑 유입 주문 — 발주 시 이 분석의 Short URL 을 쓴다(2026-07-22). */
    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketingOrder::class, 'marketing_order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShopKeywordAnalysisItem::class, 'analysis_id');
    }

    public function shortLinks(): HasMany
    {
        return $this->hasMany(ShopKeywordShortLink::class, 'analysis_id');
    }

    public function tokens(): HasMany
    {
        return $this->items()->where('kind', 'token');
    }

    /** 표시·순위체크 대상 조합(감춘 것 제외). */
    public function combos(): HasMany
    {
        return $this->items()->where('kind', 'combo')->where('hidden', false);
    }

    /** 감춘 것 포함 모든 조합 — "새로 조합" 재생성 시 중복 방지용. */
    public function allCombos(): HasMany
    {
        return $this->items()->where('kind', 'combo');
    }

    /** 노출(1~threshold위) 조합만 — 순위 오름차순. */
    public function exposed(): HasMany
    {
        return $this->combos()->whereBetween('rank', [1, $this->threshold])->orderBy('rank');
    }
}
