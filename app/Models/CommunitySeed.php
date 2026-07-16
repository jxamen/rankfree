<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 글밥(소스) — 다른 커뮤니티에서 수집한 글감. 페르소나가 소재로 변형해 사용. */
class CommunitySeed extends Model
{
    protected $fillable = ['kind', 'category_id', 'title', 'body', 'source', 'is_active', 'used_count', 'last_used_at'];

    protected $casts = ['is_active' => 'boolean', 'last_used_at' => 'datetime'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(CommunityCategory::class, 'category_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(CommunitySeedUsage::class, 'seed_id');
    }

    /** 소재 하나 뽑기 — 카테고리 매칭 우선, 적게 쓰인 것 우선(고르게 소진). */
    public static function pick(string $kind, ?int $categoryId = null): ?self
    {
        $base = static::where('kind', $kind)->where('is_active', true);
        if ($categoryId) {
            $matched = (clone $base)->where('category_id', $categoryId)->orderBy('used_count')->inRandomOrder()->first();
            if ($matched) {
                return $matched;
            }
        }

        return $base->orderBy('used_count')->inRandomOrder()->first();
    }
}
