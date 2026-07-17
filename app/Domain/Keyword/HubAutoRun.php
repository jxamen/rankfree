<?php

namespace App\Domain\Keyword;

use App\Models\KeywordCandidate;
use Illuminate\Support\Facades\Cache;

/**
 * 키워드 허브 자동 발행 상태(캐시) — 관리자 on/off·유형·진행 카운터.
 * 웹(토글/상태 폴링)과 크론(hub:auto-publish)이 공유한다. 브라우저와 무관하게 서버가 계속 드레인한다.
 */
class HubAutoRun
{
    private const KEY = 'hub:autopub';

    private const TTL_DAYS = 30;

    /** 처리 대상 = 아직 발행/보류되지 않은 쌓인 후보(pending·approved), 유형(shopping/place) 필터. */
    public static function query(?string $type)
    {
        return KeywordCandidate::whereIn('status', ['pending', 'approved'])
            ->when($type, fn ($q) => $q->whereHas('category', fn ($c) => $c->where('type', $type)));
    }

    public static function state(): array
    {
        $s = Cache::get(self::KEY, []);

        return array_merge([
            'running' => false, 'type' => null,
            'done' => 0, 'held' => 0, 'remaining' => 0,
            'started_at' => null, 'last_at' => null,
        ], is_array($s) ? $s : []);
    }

    private static function save(array $s): void
    {
        Cache::put(self::KEY, $s, now()->addDays(self::TTL_DAYS));
    }

    /** 시작 — 유형 지정, 카운터 초기화, 남은 수 집계. */
    public static function start(?string $type): array
    {
        $type = in_array($type, ['shopping', 'place'], true) ? $type : null;
        $s = [
            'running' => true, 'type' => $type,
            'done' => 0, 'held' => 0,
            'remaining' => self::query($type)->count(),
            'started_at' => now()->timestamp, 'last_at' => now()->timestamp,
        ];
        self::save($s);

        return $s;
    }

    /** 중단 — running 만 내린다(진행 카운터는 유지해 마지막 상태를 보여준다). */
    public static function stop(): array
    {
        $s = self::state();
        $s['running'] = false;
        $s['last_at'] = now()->timestamp;
        self::save($s);

        return $s;
    }

    public static function isRunning(): bool
    {
        return (bool) (self::state()['running'] ?? false);
    }

    /** 크론 배치 후 진행 갱신(누적 done/held, 남은 재계산, 하트비트). 다 드레인되면 자동 종료. */
    public static function progress(int $addDone, int $addHeld): array
    {
        $s = self::state();
        $s['done'] = (int) $s['done'] + $addDone;
        $s['held'] = (int) $s['held'] + $addHeld;
        $s['remaining'] = self::query($s['type'] ?? null)->count();
        $s['last_at'] = now()->timestamp;
        if ($s['remaining'] === 0) {
            $s['running'] = false;   // 다 드레인 → 자동 종료
        }
        self::save($s);

        return $s;
    }
}
