<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** FAQ — 기능별 자주 묻는 질문. */
class Faq extends Model
{
    protected $fillable = [
        'category', 'question', 'answer', 'sort_order', 'is_published', 'views',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'sort_order' => 'integer',
        'views' => 'integer',
    ];

    /** 기능별 카테고리 — 콘솔 메뉴 구조와 대응. */
    public const CATEGORIES = [
        '시작하기', '순위 추적', '쇼핑 순위추적', '경쟁 분석', '스마트플레이스',
        '키워드 분석', '블로그 분석', '시장·상품 분석', 'API·확장프로그램', '구독·결제', '계정',
    ];

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('is_published', true);
    }

    public function scopeSorted(Builder $q): Builder
    {
        return $q->orderBy('sort_order')->orderBy('id');
    }
}
