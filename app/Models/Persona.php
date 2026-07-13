<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 커뮤니티 페르소나 — 자동 활동자. 어드민에서 나이·성별·관심사·활동설정 등을 세부 조정. */
class Persona extends Model
{
    protected $fillable = [
        'nickname', 'avatar_color', 'bio', 'age', 'gender', 'region',
        'interests', 'tone', 'emoji_level', 'post_length',
        'activity_level', 'post_weight', 'comment_weight', 'like_weight',
        'active_hours', 'preferred_categories', 'is_active', 'auto_active',
        'joined_at', 'posts_count', 'comments_count', 'last_acted_at',
    ];

    protected $casts = [
        'interests' => 'array',
        'active_hours' => 'array',
        'preferred_categories' => 'array',
        'is_active' => 'boolean',
        'auto_active' => 'boolean',
        'joined_at' => 'datetime',
        'last_acted_at' => 'datetime',
    ];

    /** 말투 라벨(어드민·프롬프트 공용). */
    public const TONES = [
        'friendly' => '친근함',
        'expert' => '전문가',
        'humor' => '유머러스',
        'chic' => '시크·담백',
        'blunt' => '직설적',
    ];

    public const GENDERS = ['male' => '남성', 'female' => '여성', 'none' => '비공개'];

    public const ACTIVITY_LEVELS = ['active' => '활발', 'normal' => '보통', 'rare' => '뜸함'];

    public const POST_LENGTHS = ['short' => '짧게', 'mid' => '보통', 'long' => '길게'];

    public const ACTIVE_HOURS = ['morning' => '아침', 'noon' => '점심', 'evening' => '저녁', 'night' => '새벽'];

    public function posts(): HasMany
    {
        return $this->hasMany(CommunityPost::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(CommunityComment::class);
    }

    /** 아바타 이니셜(닉네임 첫 글자). */
    public function initial(): string
    {
        return mb_substr($this->nickname, 0, 1);
    }

    public function toneLabel(): string
    {
        return self::TONES[$this->tone] ?? $this->tone;
    }

    /** 활동 수준 → 활동 확률(0~1). 시뮬레이터가 참조. */
    public function activityFactor(): float
    {
        return match ($this->activity_level) {
            'active' => 1.0,
            'rare' => 0.25,
            default => 0.55,
        };
    }
}
