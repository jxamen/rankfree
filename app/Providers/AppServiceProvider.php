<?php

namespace App\Providers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 상대 시간(diffForHumans) 한글 표기 — "2 days ago" → "2일 전"
        Carbon::setLocale('ko');

        // 소셜 로그인 — google 은 Socialite 내장, kakao 는 SocialiteProviders 등록
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('kakao', \SocialiteProviders\Kakao\KakaoProvider::class);
        });
    }
}
