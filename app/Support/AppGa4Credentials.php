<?php

namespace App\Support;

use App\Domain\Seo\GoogleAnalyticsService;
use Jcurve\Ga4Insights\Contracts\Ga4Credentials;

/**
 * rankfree 앱의 GA4 자격증명 — ga4-insights 패키지에 주입.
 * 속성 ID는 환경설정(ga.property_id), 토큰은 관리자 OAuth(→서비스 계정 폴백)로 얻는다.
 */
class AppGa4Credentials implements Ga4Credentials
{
    private const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

    public function propertyId(): ?string
    {
        $id = GoogleAnalyticsService::propertyId();

        return $id !== '' ? $id : null;
    }

    public function accessToken(): ?string
    {
        return GoogleToken::token(self::SCOPE);
    }

    public function isConfigured(): bool
    {
        return GoogleAnalyticsService::configured();
    }
}
