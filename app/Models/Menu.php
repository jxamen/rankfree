<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

/** 메뉴 트리 (adjacency list). area=console|admin. route 우선, 없으면 url. */
class Menu extends Model
{
    protected $fillable = [
        'parent_id', 'area', 'name', 'route', 'url', 'icon',
        'sort_order', 'is_active', 'meta_title', 'meta_description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function parent()
    {
        return $this->belongsTo(Menu::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Menu::class, 'parent_id')->orderBy('sort_order');
    }

    public function permissions()
    {
        return $this->hasMany(MenuPermission::class);
    }

    /** 실제 링크 URL — route 이름이 있으면 그것, 없으면 url 컬럼. */
    public function resolvedUrl(): ?string
    {
        if ($this->route && Route::has($this->route)) {
            return route($this->route);
        }

        return $this->url;
    }
}
