<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'grade_id', 'operator_role_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(MemberGrade::class, 'grade_id');
    }

    public function operatorRole(): BelongsTo
    {
        return $this->belongsTo(OperatorRole::class, 'operator_role_id');
    }

    /** 최상위 관리자 — role=super / 운영자레벨 is_super / config 이메일 목록. */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'super'
            || (bool) $this->operatorRole?->is_super
            || in_array(
                strtolower((string) $this->email),
                array_map('strtolower', (array) config('rankfree.super_admins', [])),
                true,
            );
    }

    /** 운영자(관리 영역 접근 주체). */
    public function isOperator(): bool
    {
        return $this->isSuperAdmin()
            || $this->operator_role_id !== null
            || in_array($this->role, ['operator', 'admin'], true);
    }

    public function isAdmin(): bool
    {
        return $this->isOperator();
    }
}
