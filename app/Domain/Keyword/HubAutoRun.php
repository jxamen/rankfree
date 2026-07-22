<?php

namespace App\Domain\Keyword;

use App\Models\KeywordCandidate;
use Illuminate\Support\Facades\Cache;

/**
 * 키워드 자동 분석 상태(캐시) — 관리자 on/off·유형·진행 카운터.
 * 웹(토글/상태 폴링)과 크론(hub:auto-publish)이 공유한다. 브라우저와 무관하게 서버가 계속 드레인한다.
 */
class HubAutoRun
{
    private const KEY = 'hub:autopub';

    private const LOCK = 'hub:autopub:lock';

    private const TTL_DAYS = 30;

    /**
     * 처리 대상 = 아직 발행/보류되지 않은 쌓인 후보(pending·approved), 유형(shopping/place) 필터.
     * 쇼핑은 **확장 수집 시장분석이 있는 키워드만** 대상 — 없는 후보를 한 건씩 rejected 로 갈아버리는
     * 공회전(실사고: 거부 3.5만 건) 방지. 수집(v0.3.7+)으로 시장분석이 생기면 자동으로 대상에 들어온다.
     */
    public static function query(?string $type)
    {
        $base = KeywordCandidate::whereIn('status', ['pending', 'approved']);
        $marketKeywords = \App\Models\MarketAnalysis::query()->select('keyword');

        if ($type === 'place') {
            return $base->whereHas('category', fn ($c) => $c->where('type', 'place'));
        }
        if ($type === 'shopping') {
            return $base->whereHas('category', fn ($c) => $c->where('type', 'shopping'))
                ->whereIn('keyword', $marketKeywords);
        }

        return $base->where(fn ($q) => $q
            ->whereHas('category', fn ($c) => $c->where('type', 'place'))
            ->orWhere(fn ($qq) => $qq
                ->whereHas('category', fn ($c) => $c->where('type', 'shopping'))
                ->whereIn('keyword', \App\Models\MarketAnalysis::query()->select('keyword'))));
    }

    public static function state(): array
    {
        $raw = Cache::get(self::KEY, []);
        $raw = is_array($raw) ? $raw : [];

        $state = array_merge([
            'running' => false, 'type' => null,
            'done' => 0, 'held' => 0, 'remaining' => 0,
            'place_done' => 0, 'place_held' => 0, 'place_remaining' => 0,
            'shopping_done' => 0, 'shopping_held' => 0, 'shopping_remaining' => 0,
            'started_at' => null, 'last_at' => null,
        ], $raw);

        if (! array_key_exists('place_remaining', $raw)) {
            $state['place_remaining'] = ($state['type'] ?? null) === 'shopping' ? 0 : self::query('place')->count();
        }
        if (! array_key_exists('shopping_remaining', $raw)) {
            $state['shopping_remaining'] = ($state['type'] ?? null) === 'place' ? 0 : self::query('shopping')->count();
        }

        return $state;
    }

    private static function save(array $s): void
    {
        Cache::put(self::KEY, $s, now()->addDays(self::TTL_DAYS));
    }

    private static function locked(callable $callback): array
    {
        return Cache::lock(self::LOCK, 10)->block(5, $callback);
    }

    /** 시작 — type null 이면 쇼핑+플레이스 전체, 지정하면 해당 유형만. */
    public static function start(?string $type): array
    {
        return self::locked(function () use ($type) {
            $type = in_array($type, ['shopping', 'place'], true) ? $type : null;
            // 전체(remaining)는 유형별 합으로 만든다 — query(null) 는 OR+서브쿼리라 120만 행에서 수십 초씩 걸린다(실사고)
            $place = $type === 'shopping' ? 0 : self::query('place')->count();
            $shop = $type === 'place' ? 0 : self::query('shopping')->count();
            $s = [
                'running' => true, 'type' => $type,
                'done' => 0, 'held' => 0,
                'remaining' => $place + $shop,
                'place_done' => 0, 'place_held' => 0,
                'place_remaining' => $place,
                'shopping_done' => 0, 'shopping_held' => 0,
                'shopping_remaining' => $shop,
                'started_at' => now()->timestamp, 'last_at' => now()->timestamp,
                'counted_at' => now()->timestamp,   // 남은 수 실측 시각 — progress() 재계산 스로틀 기준
            ];
            self::save($s);

            return $s;
        });
    }

    /** 중단 — running 만 내린다(진행 카운터는 유지해 마지막 상태를 보여준다). */
    public static function stop(): array
    {
        return self::locked(function () {
            $s = self::state();
            $s['running'] = false;
            $s['last_at'] = now()->timestamp;
            self::save($s);

            return $s;
        });
    }

    public static function isRunning(): bool
    {
        return (bool) (self::state()['running'] ?? false);
    }

    /**
     * 크론 배치 후 진행 갱신(누적 done/held, 남은 갱신, 하트비트). 다 드레인되면 자동 종료.
     * 남은 수 실측 카운트는 120만 행 기준 3~4초짜리 쿼리 3개라 **60초에 1번만** 실측하고,
     * 그 사이엔 처리분만큼 차감한 근사치를 쓴다(0 도달 시엔 실측으로 재확인 후 종료).
     */
    public static function progress(int $addDone, int $addHeld, ?string $type = null): array
    {
        return self::locked(function () use ($addDone, $addHeld, $type) {
            $s = self::state();
            $s['done'] = (int) $s['done'] + $addDone;
            $s['held'] = (int) $s['held'] + $addHeld;
            if (in_array($type, ['place', 'shopping'], true)) {
                $s[$type.'_done'] = (int) ($s[$type.'_done'] ?? 0) + $addDone;
                $s[$type.'_held'] = (int) ($s[$type.'_held'] ?? 0) + $addHeld;
            }

            $recount = function () use (&$s): void {
                $s['place_remaining'] = ($s['type'] ?? null) === 'shopping' ? 0 : self::query('place')->count();
                $s['shopping_remaining'] = ($s['type'] ?? null) === 'place' ? 0 : self::query('shopping')->count();
                $s['remaining'] = (int) $s['place_remaining'] + (int) $s['shopping_remaining'];   // query(null) 회피
                $s['counted_at'] = now()->timestamp;
            };

            if (now()->timestamp - (int) ($s['counted_at'] ?? 0) >= 60) {
                $recount();
            } else {
                $s['remaining'] = max(0, (int) $s['remaining'] - $addDone - $addHeld);
                if (in_array($type, ['place', 'shopping'], true)) {
                    $s[$type.'_remaining'] = max(0, (int) ($s[$type.'_remaining'] ?? 0) - $addDone - $addHeld);
                }
                if ($s['remaining'] === 0) {
                    $recount();   // 근사치 0 — 실측으로 재확인해야 종료 판단이 정확하다
                }
            }

            $s['last_at'] = now()->timestamp;
            if ($s['remaining'] === 0) {
                $s['running'] = false;   // 다 드레인 → 자동 종료
            }
            self::save($s);

            return $s;
        });
    }
}
