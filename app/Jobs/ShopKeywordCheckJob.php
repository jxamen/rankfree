<?php

namespace App\Jobs;

use App\Domain\Shopping\ShopExposureRunLock;
use App\Domain\Shopping\ShopKeywordExposureAnalyzer;
use App\Models\ShopKeywordAnalysis;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * 쇼핑 유입키워드 순위 확인 자동 완주(2026-07-26) — 외부 API(28·25)로 만든 분석을 사람 없이 끝까지 확인한다.
 *
 * checkBatch 는 시간 예산(batch_sec) 만큼만 처리하므로, 남은 조합이 없어질 때까지 자기 자신을 다시 큐에 넣는다.
 * check_method='api'(기본) 는 openapi shop.json 을 서버가 직접 호출해 **확장 없이** 완결된다.
 * 브라우저 차단으로 이관된 분석은 기존 서버 lane에서 확인하고, 차단 시 공용 3분 쿨다운 뒤 재큐한다.
 */
class ShopKeywordCheckJob implements ShouldQueue
{
    use Queueable;

    /** 배치 사이 간격(초) — 연속 호출로 쿼터·IP 부담이 몰리지 않게. */
    private const GAP_SEC = 2;

    /** 안전 상한 — 무한 재큐 방지(조합 3000 / 배치 15 기준 충분). */
    private const MAX_ROUNDS = 400;

    public function __construct(public int $analysisId, public int $round = 1) {}

    public function handle(ShopKeywordExposureAnalyzer $analyzer): void
    {
        $analysis = ShopKeywordAnalysis::find($this->analysisId);
        if (! $analysis) {
            return;
        }
        // 사용자가 화면에서 중단(paused)했으면 자동 확인도 멈춘다 — 재개는 화면/API 로.
        $fallback = ShopKeywordExposureAnalyzer::usesServerFallback($analysis) || Cache::has('shop-exposure:mobile-mode:'.$analysis->id);
        if (in_array($analysis->status, ['paused', 'target_reached', 'ad_ended'], true) || (! $fallback && $this->round > self::MAX_ROUNDS)) {
            Cache::forget('shop-exposure:worker:'.$analysis->id);

            return;
        }

        if ($fallback) {
            Cache::put('shop-exposure:worker:'.$analysis->id, true, now()->addMinutes(10));
            $lock = app(ShopExposureRunLock::class);
            $lane = $lock->acquire('server', 'fallback:'.$analysis->id, (int) $analysis->id, (string) $analysis->core_keyword);
            if (! $lane['ok']) {
                if (config('queue.default') !== 'sync') {
                    self::dispatch($this->analysisId, $this->round)->delay(now()->addSeconds(15));
                }

                return;
            }
        }
        $p = $analyzer->checkBatch($analysis);
        if ($fallback && ($analysis->refresh()->collectionStopped() || (int) ($p['remaining'] ?? 0) === 0 || ! empty($p['blocked']))) {
            $lock->release('server', (int) $analysis->id);
            if ($analysis->collectionStopped() || (int) ($p['remaining'] ?? 0) === 0) {
                Cache::forget('shop-exposure:worker:'.$analysis->id);
            }
        }

        // sync 커넥션(테스트·로컬)에서는 재큐가 곧 재귀 실행이라 요청을 붙잡는다 — 한 배치만 처리하고 끝낸다.
        if (config('queue.default') === 'sync') {
            return;
        }

        // Browser fallback keeps its server lane and retries actual blocks after the shared cooldown.
        if (! $analysis->refresh()->collectionStopped() && (int) ($p['remaining'] ?? 0) > 0 && ($fallback || empty($p['blocked']))) {
            $delay = self::GAP_SEC;
            if ($fallback && ! empty($p['retry_at'])) {
                $delay = max(self::GAP_SEC, (int) ceil($p['retry_at'] / 1000 - microtime(true)));
            }
            self::dispatch($this->analysisId, $this->round + 1)->delay(now()->addSeconds($delay));
        }
    }
}
