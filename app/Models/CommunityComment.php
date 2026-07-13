<?php

namespace App\Models;

use App\Models\Concerns\HasCommunityAuthor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 커뮤니티 댓글(대댓글 지원). */
class CommunityComment extends Model
{
    use HasCommunityAuthor;

    protected $fillable = [
        'post_id', 'parent_id', 'author_type', 'persona_id', 'user_id', 'body', 'likes_count',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(CommunityPost::class, 'post_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(CommunityComment::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(CommunityComment::class, 'parent_id');
    }
}
