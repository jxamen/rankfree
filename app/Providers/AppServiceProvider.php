<?php

namespace App\Providers;

use App\Support\AppGa4Credentials;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Jcurve\Ga4Insights\Contracts\Ga4Credentials;
use SocialiteProviders\Kakao\KakaoProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // GA4 상세 분석 패키지(ga4-insights)에 앱 자격증명 주입 — 환경설정 속성ID + 구글 OAuth 토큰
        $this->app->bind(
            Ga4Credentials::class,
            AppGa4Credentials::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 상대 시간(diffForHumans) 한글 표기 — "2 days ago" → "2일 전"
        Carbon::setLocale('ko');

        // canonical·og:image 등 절대 URL 의 https 보장 — 프록시/TLS 종단 뒤에서도 APP_URL 이 https 면 강제
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // 소셜 로그인 — google 은 Socialite 내장, kakao 는 SocialiteProviders 등록
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('kakao', KakaoProvider::class);
        });
    }
}
