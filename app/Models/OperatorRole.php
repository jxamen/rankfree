<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 운영자 레벨(역할) — 슈퍼관리자/관리자/운영자. is_super=전권. */
class OperatorRole extends Model
{
    protected $fillable = ['name', 'slug', 'level', 'is_super', 'description', 'sort_order'];

    protected $casts = [
        'is_super' => 'boolean',
        'level' => 'integer',
    ];

    public function users()
    {
        return $this->hasMany(User::class, 'operator_role_id');
    }
}
