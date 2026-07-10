<?php

namespace App\Domain\SearchAdWeb;

use App\Models\NaverAdSession;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/** 웹 콘솔 세션 쿠키 저장/조회 — DB 암호화(단일 레코드). */
class WebSessionStore
{
    /** 복호화된 쿠키 문자열(없으면 null). */
    public function get(): ?string
    {
        $row = NaverAdSession::find(1);
        if (! $row || ! $row->cookies) {
            return null;
        }
        try {
            return Crypt::decryptString($row->cookies);
        } catch (Throwable) {
            return null;
        }
    }

    public function has(): bool
    {
        return $this->get() !== null;
    }

    /** 로그인 도구가 확보한 쿠키 저장(암호화). */
    public function save(string $cookies, ?string $customerId = null): void
    {
        NaverAdSession::updateOrCreate(
            ['id' => 1],
            [
                'cookies' => Crypt::encryptString($cookies),
                'customer_id' => $customerId ?? (string) config('searchadweb.customer_id'),
                'status' => 'active',
                'logged_in_at' => now(),
                'checked_at' => now(),
            ],
        );
    }

    /** 401 등 세션 만료 표시(재로그인 트리거용). */
    public function markStale(): void
    {
        NaverAdSession::where('id', 1)->update(['status' => 'stale', 'checked_at' => now()]);
    }

    public function markChecked(): void
    {
        NaverAdSession::where('id', 1)->update(['status' => 'active', 'checked_at' => now()]);
    }

    /** @return array{status:string,logged_in_at:?string,checked_at:?string,has:bool} */
    public function info(): array
    {
        $row = NaverAdSession::find(1);

        return [
            'status' => $row->status ?? 'empty',
            'logged_in_at' => $row?->logged_in_at?->toDateTimeString(),
            'checked_at' => $row?->checked_at?->toDateTimeString(),
            'has' => $this->has(),
        ];
    }
}
