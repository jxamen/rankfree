<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 회원 등급 — 무료/유료 모델별 단계 (무료·프로·대행 등). */
class MemberGrade extends Model
{
    protected $fillable = [
        'name', 'slug', 'is_paid', 'tier', 'monthly_price',
        'rank_slot_limit', 'description', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_paid' => 'boolean',
        'is_active' => 'boolean',
        'tier' => 'integer',
        'rank_slot_limit' => 'integer',
    ];

    public function users()
    {
        return $this->hasMany(User::class, 'grade_id');
    }
}
