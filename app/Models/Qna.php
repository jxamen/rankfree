<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 1:1 문의 — 회원 작성, 관리자 답변. */
class Qna extends Model
{
    protected $fillable = [
        'user_id', 'category', 'title', 'body', 'is_secret',
        'status', 'answer', 'answered_at', 'answered_by',
    ];

    protected $casts = [
        'is_secret' => 'boolean',
        'answered_at' => 'datetime',
    ];

    public const CATEGORIES = ['서비스 이용', '결제·환불', '오류 신고', '제휴·대행', '기타'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function answerer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    public function isAnswered(): bool
    {
        return $this->status === 'answered';
    }
}
