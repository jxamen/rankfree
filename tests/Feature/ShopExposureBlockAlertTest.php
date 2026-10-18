<?php

namespace Tests\Feature;

use App\Models\ShopKeywordAnalysis;
use App\Services\ShopExposureBlockAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopExposureBlockAlertTest extends TestCase
{
    public function test_groups_blocks_counts_daily_and_announces_recovery(): void
    {
        Cache::flush();
        config(['services.slack.notifications.bot_user_oauth_token' => 'test-only', 'services.slack.shop_exposure_block_channel' => 'C0C75BREWJX']);
        Http::fake(['slack.com/*' => Http::response(['ok' => true, 'ts' => '123.456'])]);
        $a = new ShopKeywordAnalysis;
        $a->id = 66;
        $a->core_keyword = '핵심 <키워드>';
        $service = app(ShopExposureBlockAlert::class);
        $this->travelTo(now('Asia/Seoul')->setTime(23, 55));
        $service->record($a, '192.0.2.1', 'extension', '403');
        $this->travel(1)->minutes();
        $service->record($a, '192.0.2.1', 'screen', '429', true);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'chat.update') && $r['ts'] === '123.456' && str_starts_with($r['text'], '<!channel>') && str_contains($r['text'], '오늘 2번째') && str_contains($r['text'], '분석 #66') && $r['channel'] === 'C0C75BREWJX');
        $service->record($a, '192.0.2.1', 'screen', 'success');
        $service->record($a, '192.0.2.1', 'screen', 'success');
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => str_contains($r['text'], '재개'));
        $this->travel(30)->minutes();
        $service->record($a, '192.0.2.1', 'extension', '보안문자');
        Http::assertSent(fn ($r) => str_contains($r['text'], '보안문자') && str_contains($r['text'], '오늘 1번째') && str_ends_with($r->url(), 'chat.postMessage'));
    }

    public function test_every_repeated_block_logs_the_same_event_shape_with_three_minute_retry(): void
    {
        Cache::flush();
        config(['services.slack.notifications.bot_user_oauth_token' => null]);
        \Illuminate\Support\Facades\Log::spy();
        $a = new ShopKeywordAnalysis;
        $a->id = 66;
        $a->core_keyword = '반복 차단';
        for ($i = 0; $i < 5; $i++) {
            $retry = now('Asia/Seoul')->addMinutes(3)->toIso8601String();
            app(ShopExposureBlockAlert::class)->record($a, '192.0.2.3', 'server', '403', $i > 0);
            \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->with('shop_exposure_block_event', \Mockery::on(fn ($data) =>
                $data['interval_ms'] === 2000 + 300 * ($i + 1) && $data['retry_at'] === $retry && $data['today_count'] === $i + 1 && $data['blocked_on_resume'] === ($i > 0)
                && array_keys($data) === ['ip', 'collector', 'analysis_id', 'core_keyword', 'kind', 'today_count', 'blocked_on_resume', 'retry_at', 'interval_ms']
            ))->once();
            $this->travel(3)->minutes();
        }
        $this->travelBack();
    }

    public function test_missing_token_sends_nothing_and_failed_slack_does_not_throw(): void
    {
        Cache::flush();
        Http::fake(['slack.com/*' => Http::response(['ok' => false], 429)]);
        $a = new ShopKeywordAnalysis;
        $a->id = 67;
        config(['services.slack.notifications.bot_user_oauth_token' => null]);
        app(ShopExposureBlockAlert::class)->record($a, '192.0.2.2', 'server', '403');
        Http::assertNothingSent();
        config(['services.slack.notifications.bot_user_oauth_token' => 'test-only']);
        app(ShopExposureBlockAlert::class)->record($a, '192.0.2.2', 'server', '429');
        Http::assertSentCount(1);
    }
}
