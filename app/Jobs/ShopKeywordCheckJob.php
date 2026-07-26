<?php

namespace App\Jobs;

use App\Domain\Shopping\ShopKeywordExposureAnalyzer;
use App\Models\ShopKeywordAnalysis;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 쇼핑 유입키워드 순위 확인 자동 완주(2026-07-26) — 외부 API(28·25)로 만든 분석을 사람 없이 끝까지 확인한다.
 *
 * checkBatch 는 시간 예산(batch_sec) 만큼만 처리하므로, 남은 조합이 없어질 때까지 자기 자신을 다시 큐에 넣는다.
 * check_method='api'(기본) 는 openapi shop.json 을 서버가 직접 호출해 **확장 없이** 완결된다.
 * 'search'(실화면·광고 판별)는 서버 IP 한도에 걸릴 수 있어 차단 시 중단하고, 확장이 켜진 화면에서 이어서 처리한다.
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
        if ($analysis->status === 'paused' || $this->round > self::MAX_ROUNDS) {
            return;
        }

        $p = $analyzer->checkBatch($analysis);

        // sync 커넥션(테스트·로컬)에서는 재큐가 곧 재귀 실행이라 요청을 붙잡는다 — 한 배치만 처리하고 끝낸다.
        if (config('queue.default') === 'sync') {
            return;
        }

        // 남은 조합이 있고 차단되지 않았으면 이어서 — 차단(429·보안문자)이면 여기서 멈추고
        // 상태(blocked)로 남겨 외부가 조회로 알 수 있게 한다.
        if ((int) ($p['remaining'] ?? 0) > 0 && empty($p['blocked'])) {
            self::dispatch($this->analysisId, $this->round + 1)->delay(now()->addSeconds(self::GAP_SEC));
        }
    }
}
