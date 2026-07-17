<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 키워드 콘텐츠 허브 — 카테고리(플레이스/쇼핑, 2계층). 시드 키워드에서 후보를 수집한다. */
class KeywordCategory extends Model
{
    protected $fillable = [
        'type', 'parent_id', 'name', 'slug', 'naver_cid', 'description', 'seed_keywords', 'sort', 'is_active', 'collected_at',
    ];

    protected $casts = [
        'seed_keywords' => 'array',
        'is_active' => 'boolean',
        'collected_at' => 'datetime',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort')->orderBy('id');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(KeywordCandidate::class, 'category_id');
    }

    public function searches(): HasMany
    {
        return $this->hasMany(KeywordSearch::class, 'category_id');
    }

    /** 시드 키워드 목록(공백 정리·중복 제거). */
    public function seedList(): array
    {
        return collect((array) $this->seed_keywords)
            ->map(fn ($k) => trim(preg_replace('/\s+/u', ' ', (string) $k)))
            ->filter(fn ($k) => $k !== '')
            ->unique()->values()->all();
    }

    /**
     * /keywords 하위 고정 경로와 겹치면 안 되는 슬러그 — 라우트가 먼저 잡아 카테고리가 404 가 된다.
     * 라우트 등록 순서·제약으로 1차 방어하고, 여기서 슬러그 자체를 피해 2중으로 막는다.
     */
    public const RESERVED_SLUGS = ['place', 'shopping', 'search'];

    /** 이름 → 슬러그(공유 슬러그와 동일 규칙 — 한글/영문/숫자 보존). 예약어는 -cat, 중복이면 -2, -3…. */
    public static function makeSlug(string $name, ?int $ignoreId = null): string
    {
        $base = KeywordSearch::slugify($name); // HasShareSlug::slugify 규칙 재사용
        if ($base === '') {
            $base = 'cat';
        }
        if (in_array($base, self::RESERVED_SLUGS, true)) {
            $base .= '-cat';
        }
        $slug = $base;
        $i = 2;
        while (static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }
}
