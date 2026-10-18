<?php

namespace App\Services;

use App\Models\ShopKeywordAnalysis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ShopExposureBlockAlert
{
    public static function interval(string $ip): int
    {
        return min(10000, 2000 + 300 * max(0, (int) Cache::get('shop-block:'.hash('sha256', $ip).':'.now('Asia/Seoul')->format('Y-m-d'), 0) - (int) Cache::get('shop-interval-offset:'.$ip.':'.now('Asia/Seoul')->format('Y-m-d'), 0)));
    }

    public static function revision(string $ip): string
    {
        return now('Asia/Seoul')->format('Y-m-d').':'.Cache::get('shop-interval-revision:'.$ip, 'v2');
    }

    public static function resetInterval(string $ip): void
    {
        Cache::lock('shop-block:lock:'.hash('sha256', $ip), 15)->block(2, function () use ($ip) {
            $day = now('Asia/Seoul')->format('Y-m-d');
            Cache::put('shop-interval-offset:'.$ip.':'.$day, (int) Cache::get('shop-block:'.hash('sha256', $ip).':'.$day, 0), now('Asia/Seoul')->endOfDay());
            Cache::put('shop-interval-revision:'.$ip, (string) \Illuminate\Support\Str::uuid(), now()->addDays(2));
        });
    }

    public function record(ShopKeywordAnalysis $analysis, string $ip, string $collector, string $kind, bool $blockedOnResume = false, ?int $intervalMs = null): void
    {
        if ($collector === 'browser' && $kind === 'unavailable') {
            app(\App\Domain\Shopping\ShopBrowserWatchdog::class)->takeover($analysis);
            return;
        }
        if ($collector === 'browser' && in_array($kind, ['403', '429', '보안문자'], true)) {
            $new = Cache::lock('shop-exposure:fallback-lock:'.$analysis->id, 5)->block(3, function () use ($analysis) {
                $key = 'shop-exposure:server-fallback:'.$analysis->id;
                if (Cache::has($key)) return false;
                Cache::forever($key, true);
                return true;
            });
            app(\App\Domain\Shopping\ShopExposureRunLock::class)->release('ip:'.$ip, (int) $analysis->id);
            // Sync is used by local/tests; production's existing worker continues without an open page.
            if ($new && $analysis->created_via === 'api' && config('queue.default') !== 'sync') {
                \App\Jobs\ShopKeywordCheckJob::dispatch((int) $analysis->id);
            }
        }
        if ($kind === 'success' && ! ($collector === 'browser' && \App\Domain\Shopping\ShopKeywordExposureAnalyzer::usesServerFallback($analysis))) {
            Cache::forget('shop-exposure:error:'.$analysis->id);
            // Human-confirmed browser recovery releases retry_at without resetting pacing.
            if ($collector === 'browser') {
                Cache::lock('shop-serp:server-lane', 30)->block(2, function () {
                    $key = \App\Domain\Shopping\NaverShopExposureService::policyKey();
                    $policy = Cache::get($key, []);
                    $policy['until'] = 0;
                    Cache::put($key, $policy, now()->addDay());
                });
            }
            if ($analysis->exists) {
                ShopKeywordAnalysis::whereKey($analysis->id)->whereIn('status', ['blocked', 'error'])->update(['status' => 'checking']);
            }
        }
        try {
            Cache::lock('shop-block:lock:'.hash('sha256', $ip), 15)->block(2, function () use ($analysis, $ip, $collector, $kind, $blockedOnResume, $intervalMs) {
                $now = now('Asia/Seoul');
                $key = 'shop-block:'.hash('sha256', $ip);
                $state = Cache::get($key, []);
                if ($kind === 'success' && empty($state['active'])) return;
                $countKey = $key.':'.$now->format('Y-m-d');
                $count = (int) Cache::get($countKey, 0);
                if ($kind !== 'success') {
                    Cache::put($countKey, ++$count, $now->copy()->endOfDay());
                }
                $event = ['ip' => $ip, 'collector' => $collector, 'analysis_id' => $analysis->id,
                    'core_keyword' => $analysis->core_keyword, 'kind' => $kind, 'today_count' => $count,
                    'blocked_on_resume' => $blockedOnResume, 'retry_at' => $kind === 'success' ? null : $now->copy()->addMinutes(3)->toIso8601String(),
                    'interval_ms' => max(self::interval($ip), min(10000, $intervalMs ?? 2000))];
                Log::info('shop_exposure_block_event', $event);
                $token = config('services.slack.notifications.bot_user_oauth_token');
                $channel = config('services.slack.shop_exposure_block_channel');
                $grouped = $kind !== 'success' && !empty($state['ts']) && ($state['last_block'] ?? 0) > $now->timestamp - 600;
                $keyword = str_replace(["\n", "\r", '<', '>', '&'], [' ', ' ', '〈', '〉', '＆'], (string) $analysis->core_keyword);
                $text = '<!channel> '.$now->format('Y-m-d H:i:s').' KST · '.$ip.'/'.$collector.' · 분석 #'.$analysis->id.' '.$keyword.' · '.($kind === 'success' ? '재개' : $kind).' · 같은 IP 오늘 '.$count.'번째 · 다음 재시도 '.($kind === 'success' ? '—' : $now->copy()->addMinutes(3)->format('H:i:s').' KST');
                $text .= ' · interval_ms '.$event['interval_ms'];
                $next = ['active' => $kind !== 'success', 'last_block' => $kind === 'success' ? 0 : $now->timestamp];
                if ($token) {
                    try {
                        $payload = ['channel' => $channel, 'text' => $text];
                        if ($grouped) $payload['ts'] = $state['ts'];
                        $response = Http::withToken($token)->timeout(5)->post('https://slack.com/api/'.($grouped ? 'chat.update' : 'chat.postMessage'), $payload);
                        if ($response->successful() && $response->json('ok')) {
                            $next['ts'] = $response->json('ts');
                        } else {
                            Log::warning('shop_exposure_slack_failed', ['status' => $response->status()]);
                        }
                    } catch (Throwable) { Log::warning('shop_exposure_slack_failed'); }
                } else {
                    Log::notice('shop_exposure_slack_configuration_required');
                }
                Cache::put($key, $next, $now->copy()->addDays(2));
            });
        } catch (Throwable) { Log::warning('shop_exposure_block_event_failed'); }
    }
}
