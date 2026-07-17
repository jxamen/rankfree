<?php

namespace Jcurve\Ga4Insights\Support;

use Jcurve\Ga4Insights\Contracts\Ga4Credentials;

/**
 * 기본 자격증명 impl — config('ga4-insights.property_id') + config('ga4-insights.access_token').
 * 정적 토큰(서비스 계정에서 발급한 Bearer 등)을 쓰는 단순 셋업/테스트용.
 * 실제 앱은 자체 impl(관리자 OAuth 등)을 Ga4Credentials 로 바인딩해 대체한다.
 */
class ConfigGa4Credentials implements Ga4Credentials
{
    public function propertyId(): ?string
    {
        $id = trim((string) config('ga4-insights.property_id', ''));

        return $id !== '' ? $id : null;
    }

    public function accessToken(): ?string
    {
        $token = config('ga4-insights.access_token');
        if (is_callable($token)) {
            $token = $token();
        }
        $token = trim((string) $token);

        return $token !== '' ? $token : null;
    }

    public function isConfigured(): bool
    {
        return $this->propertyId() !== null && $this->accessToken() !== null;
    }
}
