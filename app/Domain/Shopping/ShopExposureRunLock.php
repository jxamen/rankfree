<?php

namespace App\Domain\Shopping;

use App\Models\ShopKeywordAnalysis;
use Illuminate\Support\Facades\Cache;

/**
 * 쇼핑 노출 순위 수집 IP별 잠금(2026-09-29 대표님 지시) — 같은 IP 에서 두 곳이 동시에 m.search 를 돌면
 * 네이버가 바로 403 으로 막는다. 네이버 차단은 IP 단위라 잠금도 IP 단위다(계정별이면 같은 사무실의 두 계정은
 * 여전히 부딪히고, 한 계정이 다른 장소에서 돌리는 건 괜히 막힌다). 다른 IP 끼리는 동시에 돌아도 된다.
 *
 * scope: 브라우저 화면·확장 = 'ip:<요청 IP>'(수집하는 브라우저의 IP), 서버 배치 = 'server'(서버 IP 하나).
 * 수집이 진행되는 동안 매 요청이 갱신하고, TTL(90초) 동안 갱신이 없으면 저절로 풀린다(창 닫힘·보안문자 대기).
 * 캐시 값은 순수 배열만(운영 database 캐시에 객체 저장 금지).
 */
class ShopExposureRunLock
{
    private const KEY = 'shop-exposure:runner:';

    public const TTL = 90;

    /**
     * 잠금 획득·갱신. 같은 분석은 새 실행자가 인계한다. 인계된 실행자는 다시 획득할 수 없다.
     *
     * @return array{ok: bool, holder: ?array, taken_over?: bool}
     */
    public function acquire(string $scope, string $owner, int $analysisId, string $keyword = '', bool $renew = true): array
    {
        $key = self::KEY.$scope;

        return Cache::lock($key.':mutex', 5)->block(3, function () use ($key, $scope, $owner, $analysisId, $keyword, $renew) {
            $cur = Cache::get($key);
            $revoked = $key.':revoked:'.$analysisId.':'.hash('sha256', $owner);
            if (Cache::has($revoked)) {
                return ['ok' => false, 'holder' => $cur, 'taken_over' => true];
            }
            // Waiting screens inspect ownership without acquiring or extending the lease.
            if (! $renew) {
                return ['ok' => ! is_array($cur) || (($cur['owner'] ?? '') === $owner && (int) ($cur['analysis_id'] ?? 0) === $analysisId), 'holder' => $cur, 'taken_over' => is_array($cur) && (int) ($cur['analysis_id'] ?? 0) === $analysisId && ($cur['owner'] ?? '') !== $owner];
            }
            // Result saves outlive a cache lease: a background poll must not jump ahead
            // of work actually progressing on this IP, even just after the 90s TTL.
            $candidateSaving = false;
            if (str_starts_with($scope, 'ip:')) {
                $recent = now()->subMinutes(2);
                $candidateSaving = ShopKeywordAnalysis::whereKey($analysisId)
                    ->whereHas('combos', fn ($q) => $q->where('checked_at', '>', $recent))->exists();
                if (! $candidateSaving) {
                    $active = ShopKeywordAnalysis::where('collection_ip', substr($scope, 3))
                        ->where('id', '!=', $analysisId)->where('status', 'checking')
                        ->whereHas('combos', fn ($q) => $q->where('checked_at', '>', $recent))
                        ->withMax('combos', 'checked_at')->orderByDesc('combos_max_checked_at')->first();
                    if ($active) {
                        return ['ok' => false, 'holder' => ['analysis_id' => (int) $active->id,
                            'keyword' => $active->core_keyword, 'ip' => $active->collection_ip,
                            'source' => $active->collection_source]];
                    }
                }
            }
            if (is_array($cur) && (int) ($cur['analysis_id'] ?? 0) === $analysisId && ($cur['owner'] ?? '') !== $owner) {
                Cache::forever($key.':revoked:'.$analysisId.':'.hash('sha256', $cur['owner']), true);
            }
            if (is_array($cur) && (int) ($cur['analysis_id'] ?? 0) !== $analysisId) {
                $holder = ShopKeywordAnalysis::find((int) ($cur['analysis_id'] ?? 0));
                $lastSeen = max((int) ($cur['at'] ?? 0), $holder?->collection_seen_at?->timestamp ?? 0);
                $stopped = ! $holder || in_array($holder->status, ['done', 'target_reached', 'ad_ended', 'paused'], true);
                // A stale heartbeat holder yields to an analysis with actual recent saves.
                $holderSaving = $holder?->combos()->where('checked_at', '>', now()->subMinutes(2))->exists();
                if (! $stopped && $lastSeen > now()->timestamp - self::TTL && (! $candidateSaving || $holderSaving)) {
                    return ['ok' => false, 'holder' => $cur];
                }
            }
            $ip = str_starts_with($scope, 'ip:') ? substr($scope, 3) : null;
            $ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
            $source = str_starts_with($owner, 'ext:') ? '확장 수집기' : ($ip ? '브라우저' : '서버');
            ShopKeywordAnalysis::whereKey($analysisId)->update(['collection_ip' => $ip, 'collection_source' => $source, 'collection_seen_at' => now()]);
            Cache::put($key, ['ip' => $ip, 'source' => $source, 'owner' => $owner, 'analysis_id' => $analysisId, 'keyword' => $keyword, 'at' => now()->timestamp], self::TTL);

            return ['ok' => true, 'holder' => null];
        });
    }

    /** 이 scope 에서 이 분석을 돌리던 잠금을 푼다(중단·완료). */
    public function release(string $scope, int $analysisId, ?string $owner = null): void
    {
        $key = self::KEY.$scope;
        Cache::lock($key.':mutex', 5)->block(3, function () use ($key, $analysisId, $owner) {
            $cur = Cache::get($key);
            if (is_array($cur) && (int) ($cur['analysis_id'] ?? 0) === $analysisId && ($owner === null || ($cur['owner'] ?? '') === $owner)) {
                Cache::forget($key);
            }
        });
    }

    /** 화면에 띄울 안내 — 같은 IP 에서 다른 수집이 도는 분석. */
    public function busyMessage(array $holder, bool $isAdmin = false): string
    {
        $kw = trim((string) ($holder['keyword'] ?? ''));

        $ip = $holder['ip'] ?? null;
        $display = $ip ? ($isAdmin ? $ip : '*** (마스킹)') : '미확인';
        $collector = (string) ($holder['source'] ?? '수집기 미확인');

        return '수집 IP: '.$display.' · '.$collector.' — 같은 인터넷 주소(IP)에서 다른 수집이 진행 중이에요 — 분석 #'.(int) ($holder['analysis_id'] ?? 0).($kw !== '' ? " ({$kw})" : '')
            .'. 동시에 돌면 네이버가 막아요. 끝나면 자동으로 이어서 시작합니다.';
    }
}
