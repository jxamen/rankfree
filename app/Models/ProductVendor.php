<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 상품 × 업체 배분 — 승인 시 비율(%)/고정 수량대로 업체에 자동 발주. field_map 은 업체별 페이로드 매핑. */
class ProductVendor extends Model
{
    public const ALLOC_TYPES = [
        'ratio' => '비율(%)',
        'fixed' => '고정 수량',
    ];

    protected $fillable = [
        'product_id', 'vendor_id', 'alloc_type', 'alloc_value', 'field_map', 'sheet_tab', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'alloc_value' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean',
        'field_map' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(MarketingProduct::class, 'product_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
