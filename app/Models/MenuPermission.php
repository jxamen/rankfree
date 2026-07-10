<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 권한 매트릭스 — 메뉴 × 주체(grade|role) × 접근/입력/수정/삭제. */
class MenuPermission extends Model
{
    protected $fillable = [
        'menu_id', 'subject_type', 'subject_id',
        'can_access', 'can_create', 'can_update', 'can_delete',
    ];

    protected $casts = [
        'can_access' => 'boolean',
        'can_create' => 'boolean',
        'can_update' => 'boolean',
        'can_delete' => 'boolean',
    ];

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }
}
