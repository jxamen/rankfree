<?php

namespace App\Services;

use App\Models\ShopKeywordAnalysis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ShopExposureBlockAlert
{
    public function record(ShopKeywordAnalysis $analysis, string $ip, string $collector, string $kind, bool $blockedOnResume = false): void
    {
        try {
            Cache::lock('shop-block:lock:'.hash('sha256', $ip), 15)->block(2, function () use ($analysis, $ip, $collector, $kind, $blockedOnResume) {
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
                    'blocked_on_resume' => $blockedOnResume, 'retry_at' => $kind === 'success' ? null : $now->copy()->addMinutes(3)->toIso8601String()];
                Log::info('shop_exposure_block_event', $event);
                $token = config('services.slack.notifications.bot_user_oauth_token');
                $channel = config('services.slack.shop_exposure_block_channel');
                $grouped = $kind !== 'success' && !empty($state['ts']) && ($state['last_block'] ?? 0) > $now->timestamp - 600;
                $keyword = str_replace(["\n", "\r", '<', '>', '&'], [' ', ' ', '〈', '〉', '＆'], (string) $analysis->core_keyword);
                $text = '<!channel> '.$now->format('Y-m-d H:i:s').' KST · '.$ip.'/'.$collector.' · 분석 #'.$analysis->id.' '.$keyword.' · '.($kind === 'success' ? '재개' : $kind).' · 같은 IP 오늘 '.$count.'번째 · 다음 재시도 '.($kind === 'success' ? '—' : $now->copy()->addMinutes(3)->format('H:i:s').' KST');
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
