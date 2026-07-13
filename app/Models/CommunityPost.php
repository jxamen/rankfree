<?php

namespace App\Models;

use App\Models\Concerns\HasCommunityAuthor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 커뮤니티 게시글. */
class CommunityPost extends Model
{
    use HasCommunityAuthor;

    protected $fillable = [
        'category_id', 'author_type', 'persona_id', 'user_id',
        'title', 'body', 'views', 'likes_count', 'comments_count', 'is_pinned',
    ];

    protected $casts = ['is_pinned' => 'boolean'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(CommunityCategory::class, 'category_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(CommunityComment::class, 'post_id');
    }

    public function likes(): HasMany
    {
        return $this->hasMany(CommunityLike::class, 'likeable_id')->where('likeable_type', 'post');
    }

    /** 본문 미리보기(목록용). */
    public function excerpt(int $len = 120): string
    {
        return \Illuminate\Support\Str::limit(preg_replace('/\s+/u', ' ', $this->body), $len);
    }
}
