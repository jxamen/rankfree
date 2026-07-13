<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

/** 메뉴 트리 (adjacency list). is_group=컨테이너(대/중분류), 아니면 페이지 항목. */
class Menu extends Model
{
    protected $fillable = [
        'parent_id', 'area', 'is_group', 'name', 'route', 'url', 'target', 'icon',
        'sort_order', 'is_active', 'meta_title', 'meta_description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_group' => 'boolean',
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

    /**
     * 실제 링크 URL — route 이름이 있으면 그것, 없으면 url 컬럼.
     * route명 입력 오차(슬래시로 입력·area 접두 누락)를 관대하게 정규화한다.
     *   예: "console/talk-contacts" → console.talk-contacts, "talk-contacts" → console.talk-contacts
     */
    public function resolvedUrl(): ?string
    {
        if ($this->route) {
            foreach ($this->routeCandidates() as $name) {
                if (Route::has($name)) {
                    return route($name);
                }
            }
        }

        return $this->url;
    }

    /** route명 후보(입력 오차 보정). */
    private function routeCandidates(): array
    {
        $raw = trim((string) $this->route);
        $dotted = trim(str_replace(['/', ' '], ['.', ''], $raw), '.');   // 슬래시·공백 → 점
        $cands = [$raw, $dotted];
        $prefix = $this->area.'.';
        if ($this->area && ! str_starts_with($dotted, $prefix)) {
            $cands[] = $prefix.$dotted;                                    // area 접두 보정
        }

        return array_values(array_unique(array_filter($cands)));
    }
}
