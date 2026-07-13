<?php

namespace App\Models\Concerns;

use App\Models\Persona;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 커뮤니티 작성자 공용 — 게시글/댓글이 페르소나 또는 실사용자로 작성됨.
 * author_type('persona'|'user') + persona_id / user_id.
 */
trait HasCommunityAuthor
{
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 표시용 작성자 이름. */
    public function authorName(): string
    {
        if ($this->author_type === 'persona') {
            return $this->persona?->nickname ?? '탈퇴한 사용자';
        }

        return $this->user?->name ?? '탈퇴한 사용자';
    }

    /** 아바타 이니셜. */
    public function authorInitial(): string
    {
        return mb_substr($this->authorName(), 0, 1);
    }

    /** 아바타 배경색(페르소나는 지정색, 실사용자는 이름 해시색). */
    public function authorColor(): string
    {
        if ($this->author_type === 'persona' && $this->persona?->avatar_color) {
            return $this->persona->avatar_color;
        }
        $palette = ['#0052ff', '#05b169', '#f4b000', '#8b5cf6', '#ec4899', '#fb923c', '#0891b2'];

        return $palette[crc32($this->authorName()) % count($palette)];
    }

    public function isBy(string $type, int $id): bool
    {
        return $this->author_type === $type
            && (int) ($type === 'persona' ? $this->persona_id : $this->user_id) === $id;
    }
}
