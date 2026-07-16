<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 글밥 사용 이력 — 언제, 어떤 페르소나가, 어떤 AI 로 재작성해, 어떤 글/댓글이 됐는지. */
class CommunitySeedUsage extends Model
{
    protected $fillable = ['seed_id', 'persona_id', 'used_for', 'post_id', 'comment_id', 'provider'];

    public function seed(): BelongsTo
    {
        return $this->belongsTo(CommunitySeed::class, 'seed_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(CommunityPost::class, 'post_id');
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(CommunityComment::class, 'comment_id');
    }
}
