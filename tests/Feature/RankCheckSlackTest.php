<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Support\RankCheckSlack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RankCheckSlackTest extends TestCase
{
    use RefreshDatabase;

    public function test_웹훅이_비어_있으면_보내지_않는다(): void
    {
        Http::fake();

        RankCheckSlack::done('플레이스', 10, 10, 7, now()->subMinutes(5));

        Http::assertNothingSent();
    }

    public function test_완료_건수와_순위권_안팎을_채널로_보낸다(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        AppSetting::write('slack.rank_done_webhook_url', 'https://hooks.slack.com/services/T0/B0/xxx');

        RankCheckSlack::done('플레이스', 418, 418, 300, now()->subMinutes(23));

        Http::assertSent(fn ($req) => str_contains($req['text'], '플레이스 순위체크 완료')
            && str_contains($req['text'], '418/418건 기록')
            && str_contains($req['text'], '순위권 안 300 · 밖 118')
            && ! str_contains($req['text'], '일부만'));
    }

    public function test_중간에_멈추면_남은_건수를_경고로_붙인다(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        AppSetting::write('slack.rank_done_webhook_url', 'https://hooks.slack.com/services/T0/B0/xxx');

        RankCheckSlack::done('쇼핑', 5, 2, 1, now()->subMinutes(1), true);

        Http::assertSent(fn ($req) => str_contains($req['text'], '일부만 기록됨(3건 남음)') && str_contains($req['text'], '차단 감지'));
    }

    public function test_슬랙이_실패해도_예외를_내지_않는다(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('boom', 500)]);
        AppSetting::write('slack.rank_done_webhook_url', 'https://hooks.slack.com/services/T0/B0/xxx');

        RankCheckSlack::done('쇼핑', 1, 1, 1, now());

        $this->assertTrue(true);
    }

    public function test_전용_웹훅이_없으면_제이커브_단체_웹훅과_담당자_태그를_쓴다(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        AppSetting::write('slack.rank_done_webhook_url', '');
        config(['services.slack.order_reminder_mentions' => 'U0C4ZMY6RPV']);
        AppSetting::write('slack.missed_order_webhook_url', 'https://hooks.slack.com/services/T0/B1/group');

        RankCheckSlack::done('플레이스', 2, 2, 1, now());

        Http::assertSent(fn ($req) => str_contains($req->url(), '/B1/group') && str_contains($req['text'], '<@U0C4ZMY6RPV>'));
    }
}
