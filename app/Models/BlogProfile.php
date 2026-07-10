<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 블로그 리뷰어 품질 프로필 캐시 — crm crm_blog_profile. */
class BlogProfile extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'blog_id';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'power_blog' => 'boolean',
        'ok' => 'boolean',
        'fetched_at' => 'datetime',
    ];
}
