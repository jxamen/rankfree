<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 마케팅 상품 대분류 유형 (REWARD·EXPERIENCE·SNS·BLOG_REVIEW 등, DB 관리). */
class ProductType extends Model
{
    protected $fillable = ['code', 'name', 'description', 'has_fulfillment', 'sort_order', 'is_active'];

    protected $casts = ['has_fulfillment' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];

    public function subTypes(): HasMany
    {
        return $this->hasMany(ProductSubType::class, 'product_type', 'code')->orderBy('sort_order');
    }
}
