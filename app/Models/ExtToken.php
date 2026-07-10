<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** 크롬 확장용 API 토큰. 평문은 발급 시 1회만 노출, DB에는 sha256 해시만 저장. */
#[Fillable(['user_id', 'name', 'token', 'last_used_at'])]
class ExtToken extends Model
{
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 토큰 발급. @return array{0: self, 1: string} [모델, 평문 토큰] */
    public static function issue(User $user, string $name = 'chrome-extension'): array
    {
        $plain = Str::random(48);

        $token = static::create([
            'user_id' => $user->id,
            'name' => $name,
            'token' => hash('sha256', $plain),
        ]);

        return [$token, $plain];
    }

    public static function findByPlain(?string $plain): ?self
    {
        if ($plain === null || $plain === '') {
            return null;
        }

        return static::query()->where('token', hash('sha256', $plain))->first();
    }
}
