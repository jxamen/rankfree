<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** 팝업 — 대시보드 진입 시 노출. 위치·크기·기간 지정. */
class Popup extends Model
{
    protected $fillable = [
        'title', 'body', 'position', 'width', 'is_active', 'dismissible', 'sort_order', 'starts_at', 'ends_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'dismissible' => 'boolean',
        'width' => 'integer',
        'sort_order' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public const POSITIONS = [
        'center' => '중앙',
        'top-left' => '좌상단', 'top-right' => '우상단',
        'bottom-left' => '좌하단', 'bottom-right' => '우하단',
    ];

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

    public function positionLabel(): string
    {
        return self::POSITIONS[$this->position] ?? $this->position;
    }
}
