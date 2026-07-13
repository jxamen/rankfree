<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 커뮤니티 카테고리. */
class CommunityCategory extends Model
{
    protected $fillable = ['slug', 'name', 'description', 'icon', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function posts(): HasMany
    {
        return $this->hasMany(CommunityPost::class, 'category_id');
    }
}
