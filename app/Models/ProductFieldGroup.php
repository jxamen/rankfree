<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 동적 필드 그룹 (basic·schedule_quantity·custom). */
class ProductFieldGroup extends Model
{
    protected $fillable = ['product_id', 'code', 'name', 'description', 'is_system', 'sort_order'];

    protected $casts = ['is_system' => 'boolean', 'sort_order' => 'integer'];

    public function fields(): HasMany
    {
        return $this->hasMany(ProductField::class, 'group_id')->orderBy('sort_order');
    }
}
