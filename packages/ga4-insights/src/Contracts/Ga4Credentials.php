<?php

namespace Jcurve\Ga4Insights\Contracts;

/**
 * GA4 자격증명 공급 — 이 패키지를 이식하는 앱이 구현한다.
 * 인증 방식(관리자 OAuth · 서비스 계정 · 정적 토큰)과 속성 ID 출처를 앱이 자유롭게 정하고,
 * 패키지는 이 인터페이스로만 GA4에 접근한다(완전 분리).
 */
interface Ga4Credentials
{
    /** GA4 속성 ID(숫자). 미설정이면 null. */
    public function propertyId(): ?string;

    /** analytics.readonly 스코프 액세스 토큰(Bearer). 실패/미설정이면 null. */
    public function accessToken(): ?string;

    /** 속성 ID + 토큰이 모두 준비됐는지. */
    public function isConfigured(): bool;
}
