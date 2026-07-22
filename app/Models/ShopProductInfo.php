<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 확장이 상품 페이지에서 수집한 상품정보(제목·브랜드·가격·SEO태그) — 노출 키워드 조합 재료(25). */
class ShopProductInfo extends Model
{
    protected $fillable = [
        'user_id', 'channel_product_id', 'title', 'brand', 'mall_name', 'price', 'seller_tags', 'category', 'thumbnail_url', 'collected_at',
    ];

    protected $casts = [
        'price' => 'integer',
        'seller_tags' => 'array',
        'collected_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
