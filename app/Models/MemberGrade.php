<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 회원 등급 — 무료/유료 모델별 단계 (무료·프로·대행 등). */
class MemberGrade extends Model
{
    /** 횟수 제한 가능한 기능(월간). 값 -1=무제한, 0=미제공, N=월 N회. */
    public const FEATURES = [
        'keyword_analysis' => '키워드 분석(API)',
        'market_analysis' => '쇼핑 시장 분석',
        'product_analysis' => '상품 리뷰 분석',
        'compete_analysis' => '경쟁 분석',
    ];

    protected $fillable = [
        'name', 'slug', 'is_paid', 'tier', 'monthly_price',
        'rank_slot_limit', 'feature_limits', 'description', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_paid' => 'boolean',
        'is_active' => 'boolean',
        'tier' => 'integer',
        'rank_slot_limit' => 'integer',
        'feature_limits' => 'array',
    ];

    /** 기능 월간 한도 — 미설정이면 -1(무제한). */
    public function featureLimit(string $key): int
    {
        $limits = (array) $this->feature_limits;

        return array_key_exists($key, $limits) ? (int) $limits[$key] : -1;
    }

    public function users()
    {
        return $this->hasMany(User::class, 'grade_id');
    }
}
