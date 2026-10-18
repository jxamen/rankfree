<?php

namespace App\Domain\Shopping;

use App\Jobs\ShopKeywordCheckJob;
use App\Models\ShopKeywordAnalysis;
use Illuminate\Support\Facades\Cache;

class ShopBrowserWatchdog
{
    public function takeover(ShopKeywordAnalysis $analysis, bool $requireStale = false): bool
    {
        if ($analysis->collectionStopped() || ! $analysis->combos()->whereNull('rank')->exists()) return false;
        return Cache::lock('shop-exposure:fallback-lock:'.$analysis->id, 5)->block(3, function () use ($analysis, $requireStale) {
            $analysis->refresh();
            if ($analysis->collectionStopped()) return false;
            if ($requireStale && ($analysis->collection_seen_at ?? $analysis->created_at)->gt(now()->subSeconds(60))) return false;
            $key = 'shop-exposure:server-fallback:'.$analysis->id;
            if (Cache::has($key)) return false;
            Cache::forever($key, true);
            Cache::forever('shop-exposure:browser-unavailable:'.$analysis->id, true);
            if ($analysis->collection_ip) app(ShopExposureRunLock::class)->release('ip:'.$analysis->collection_ip, $analysis->id);
            if (config('queue.default') !== 'sync') ShopKeywordCheckJob::dispatch($analysis->id, serverOnly: true);
            return true;
        });
    }

    // Only absence fallback is recoverable by polling. Actual blocks retain their cooldown.
    public function recover(ShopKeywordAnalysis $analysis): void
    {
        Cache::lock('shop-exposure:fallback-lock:'.$analysis->id, 5)->block(3, function () use ($analysis) {
            if (! Cache::has('shop-exposure:browser-unavailable:'.$analysis->id)) return;
            $batch = Cache::lock('shop-exposure:'.$analysis->id, 5);
            if (! $batch->get()) return;
            try {
                Cache::forget('shop-exposure:browser-unavailable:'.$analysis->id);
                Cache::forget('shop-exposure:server-fallback:'.$analysis->id);
                app(ShopExposureRunLock::class)->release('server', $analysis->id);
            } finally { $batch->release(); }
        });
    }

    public function scan(): int
    {
        $count = 0;
        ShopKeywordAnalysis::whereNotIn('status', ['paused','done','target_reached','ad_ended'])
            ->where(function ($q) {
                $q->whereIn('collection_source', ['브라우저', '확장 수집기'])
                    ->orWhere(function ($q) { $q->where('created_via', 'api')->whereNull('collection_seen_at'); });
            })
            ->whereRaw('COALESCE(collection_seen_at, created_at) <= ?', [now()->subSeconds(60)])
            ->whereHas('combos', fn ($q) => $q->whereNull('rank'))
            ->each(function ($analysis) use (&$count) { if ($this->takeover($analysis, true)) $count++; });
        return $count;
    }
}
