<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

/** 메뉴 트리 (adjacency list). is_group=컨테이너(대/중분류), 아니면 페이지 항목. */
class Menu extends Model
{
    protected $fillable = [
        'parent_id', 'area', 'is_group', 'name', 'route', 'url', 'target', 'icon',
        'sort_order', 'is_active', 'meta_title', 'meta_description', 'meta_keywords',
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
     * 현재 라우트명에 매칭되는 메뉴의 SEO 값. 'site' area(공개 페이지) 우선.
     * 페이지가 @section 으로 직접 지정하면 레이아웃에서 그쪽이 우선한다(여긴 폴백값).
     *
     * @return array{title:?string, description:?string, keywords:?string}
     */
    public static function seo(?string $routeName): array
    {
        $empty = ['title' => null, 'description' => null, 'keywords' => null];
        if (! $routeName) {
            return $empty;
        }

        // 레이아웃(<head>)에서 호출되므로 DB 문제(테이블 부재·연결 오류)로 페이지가 500 나지 않게 방어.
        try {
            $m = static::query()
                ->where('route', $routeName)
                ->where(fn ($q) => $q->whereNotNull('meta_title')->orWhereNotNull('meta_description')->orWhereNotNull('meta_keywords'))
                ->orderByRaw("CASE WHEN area = 'site' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->first();
        } catch (\Throwable $e) {
            return $empty;
        }

        return $m
            ? ['title' => $m->meta_title, 'description' => $m->meta_description, 'keywords' => $m->meta_keywords]
            : $empty;
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
