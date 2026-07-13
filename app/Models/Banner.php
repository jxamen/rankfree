<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** 홍보 배너 — 대시보드 노출(신규 상품/업체/프로모션). */
class Banner extends Model
{
    protected $fillable = [
        'title', 'subtitle', 'body', 'image_url', 'link_url', 'link_label',
        'type', 'bg_color', 'text_color', 'sort_order', 'is_active', 'starts_at', 'ends_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public const TYPES = ['promo' => '프로모션', 'product' => '신규 상품', 'company' => '업체 홍보'];

    /** 노출 대상 — 활성 + 기간 도래분. */
    public function scopeActiveNow(Builder $q): Builder
    {
        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public function scopeSorted(Builder $q): Builder
    {
        return $q->orderBy('sort_order')->orderByDesc('id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
