<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 세부 유형 (유형별, 관리자 추가·수정 가능). */
class ProductSubType extends Model
{
    protected $fillable = ['product_type', 'code', 'name', 'description', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];
}
