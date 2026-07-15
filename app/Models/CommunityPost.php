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

    /** 본문 미리보기(목록·검색용) — HTML 태그 제거한 평문. */
    public function excerpt(int $len = 120): string
    {
        return \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $this->body))), $len);
    }

    /**
     * 상세 렌더용 안전 HTML.
     * 저장 시 새니타이즈된 리치 텍스트(HTML)는 그대로, 태그 없는 기존 평문은 이스케이프+줄바꿈.
     */
    public function bodyHtml(): string
    {
        $body = (string) $this->body;
        if (! preg_match('/<[a-z][\s\S]*>/i', $body)) {
            return nl2br(e($body));   // 평문(구 데이터) — XSS 안전하게
        }

        return $body;                  // 리치 텍스트 — 저장 시 HtmlSanitizer로 정리됨
    }
}
