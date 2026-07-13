<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 좋아요 — 글/댓글 × 페르소나/실사용자. */
class CommunityLike extends Model
{
    protected $fillable = ['likeable_type', 'likeable_id', 'liker_type', 'liker_id'];
}
