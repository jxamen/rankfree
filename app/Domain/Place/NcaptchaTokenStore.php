<?php

namespace App\Domain\Place;

use App\Models\PlaceRankToken;

/**
 * nCaptcha 토큰 저장/조회 — crm sp_rank_token_* 이식.
 * pcmap-api graphql 은 브라우저 nCaptcha SDK 가 만든 x-wtm-ncaptcha-token 없으면 405/429.
 * 토큰은 세션 범용(키워드/페이지 무관) → 1개만 있으면 서버가 전 키워드 순위체크 가능.
 * 로컬 발급 도구(Playwright)가 토큰을 발급해 저장 → PlaceRankChecker 가 읽어 사용.
 */
class NcaptchaTokenStore
{
    /** 저장된 토큰(없으면 config 폴백, 그것도 없으면 ''). */
    public static function get(): string
    {
        $row = PlaceRankToken::find(1);
        $tok = $row ? trim((string) $row->token) : '';
        if ($tok !== '') {
            return $tok;
        }

        return (string) config('rankfree.place.ncaptcha_fallback', '');
    }

    /** 토큰 저장(로컬 발급 도구가 API 통해 호출). */
    public static function save(string $token): void
    {
        PlaceRankToken::updateOrCreate(
            ['id' => 1],
            ['token' => trim($token), 'updated_at' => now()],
        );
    }

    /** 갱신 시각(관리화면 표시용). */
    public static function updatedAt(): ?string
    {
        $row = PlaceRankToken::find(1);

        return $row && $row->updated_at ? $row->updated_at->toDateTimeString() : null;
    }

    public static function has(): bool
    {
        return self::get() !== '';
    }
}
