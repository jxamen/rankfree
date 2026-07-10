<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** 최상위 관리자(슈퍼어드민) — role=super 또는 config 이메일 목록 기준. */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'super'
            || in_array(
                strtolower((string) $this->email),
                array_map('strtolower', (array) config('rankfree.super_admins', [])),
                true,
            );
    }

    /** 관리자(슈퍼 포함). */
    public function isAdmin(): bool
    {
        return $this->isSuperAdmin() || $this->role === 'admin';
    }
}
