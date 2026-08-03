<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 쇼핑 순위체크 작업(2026-08-03) — 확장 워커 풀이 가져가 처리한다.
 * 배경: openapi shop.json 종료(공지 32564) + 서버 크롤링 차단(418/캡차) → 확장 same-origin 수집만 가능.
 */
class ShopRankJob extends Model
{
    protected $fillable = [
        'keyword', 'target_type', 'product_id', 'id_kind', 'mall_name', 'pages',
        'status', 'claimed_by', 'claimed_at', 'lease_until', 'attempts', 'available_at',
        'rank', 'found', 'ad_exposed', 'list_total', 'scanned', 'title', 'price', 'link', 'image', 'error',
        'source', 'slot_id', 'request_token', 'user_id', 'finished_at',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
        'lease_until' => 'datetime',
        'available_at' => 'datetime',
        'finished_at' => 'datetime',
        'found' => 'boolean',
        'ad_exposed' => 'boolean',
        'rank' => 'integer',
        'pages' => 'integer',
        'attempts' => 'integer',
        'price' => 'integer',
    ];

    /**
     * 리스 기간(초) — 워커가 이 안에 결과를 못 주면 다른 워커가 회수한다.
     * 1000위(13페이지) 수집은 페이지 간 간격까지 더해 2~4분 걸린다. 짧게 잡으면
     * 정상 수집 중인 작업을 다른 워커가 뺏어가 같은 키워드를 두 번 긁는다.
     */
    public const LEASE_SECONDS = 420;

    /** 재시도 상한 — 넘으면 failed. 캡차 백오프로 계속 되돌아오는 걸 막는다. */
    public const MAX_ATTEMPTS = 3;

    /**
     * 작업 N개를 원자적으로 claim.
     *
     * 🔴 select → update 로 나누면 워커 여러 대가 같은 작업을 가져간다(check-then-act).
     *    id 를 먼저 고정하고 **조건부 UPDATE 의 영향 행 수**로 소유권을 판정한다.
     *
     * @return list<self>
     */
    public static function claim(string $workerId, int $limit = 3): array
    {
        $now = now();
        $claimed = [];

        for ($i = 0; $i < $limit; $i++) {
            $id = static::query()
                ->where(fn ($q) => $q
                    // 아직 아무도 안 가져간 것
                    ->where(fn ($w) => $w->where('status', 'pending')
                        ->where(fn ($a) => $a->whereNull('available_at')->orWhere('available_at', '<=', $now)))
                    // 리스가 끊긴 것(워커가 죽었다) — 회수
                    ->orWhere(fn ($w) => $w->where('status', 'claimed')->where('lease_until', '<', $now)))
                ->orderBy('id')
                ->value('id');

            if (! $id) {
                break;
            }

            // 조건부 UPDATE — 이 시점에 상태가 그대로일 때만 내 것이 된다
            $ok = static::query()->whereKey($id)
                ->where(fn ($q) => $q->where('status', 'pending')
                    ->orWhere(fn ($w) => $w->where('status', 'claimed')->where('lease_until', '<', $now)))
                ->update([
                    'status' => 'claimed',
                    'claimed_by' => $workerId,
                    'claimed_at' => $now,
                    'lease_until' => $now->copy()->addSeconds(self::LEASE_SECONDS),
                    'attempts' => DB::raw('attempts + 1'),
                    'updated_at' => $now,
                ]);

            if ($ok === 1) {
                $claimed[] = static::find($id);
            }
        }

        return array_values(array_filter($claimed));
    }

    /** 결과 확정. */
    public function complete(array $res): void
    {
        $this->fill([
            'status' => 'done',
            'rank' => (int) ($res['rank'] ?? 0),
            'found' => (bool) ($res['found'] ?? false),
            'ad_exposed' => (bool) ($res['ad'] ?? false),
            'list_total' => (int) ($res['total'] ?? 0),
            'scanned' => (int) ($res['scanned'] ?? 0),
            'title' => $res['title'] ?? null,
            'price' => ($res['price'] ?? 0) ?: null,
            'link' => $res['link'] ?? null,
            'image' => $res['image'] ?? null,
            'error' => null,
            'finished_at' => now(),
        ])->save();
    }

    /**
     * 워커가 실패를 알렸다 — 재시도 여지가 있으면 백오프 후 pending 으로 되돌린다.
     * 캡차는 그 PC 가 잠시 못 쓰는 것뿐이라 작업 자체를 버리지 않는다(다른 워커가 집어간다).
     */
    public function failAttempt(string $error, int $backoffSeconds = 120): void
    {
        $dead = $this->attempts >= self::MAX_ATTEMPTS;

        $this->fill([
            'status' => $dead ? 'failed' : 'pending',
            'error' => mb_substr($error, 0, 60),
            'claimed_by' => null,
            'claimed_at' => null,
            'lease_until' => null,
            'available_at' => $dead ? null : now()->addSeconds($backoffSeconds),
            'finished_at' => $dead ? now() : null,
        ])->save();
    }

    /** 워커 생존 표식 TTL(초) — 이보다 오래 조용하면 켜진 PC 가 없다고 본다. */
    private const WORKER_SEEN_TTL = 300;

    private const WORKER_SEEN_KEY = 'shop-rank:worker-seen';

    /** 워커가 큐를 물어봤다 = 살아 있다. (캐시엔 문자열만 담는다 — 운영 database 캐시에 객체 금지) */
    public static function touchWorkerSeen(string $workerId): void
    {
        Cache::put(self::WORKER_SEEN_KEY, $workerId, self::WORKER_SEEN_TTL);
    }

    /** 최근에 큐를 물어본 워커가 있는가. 없으면 기다려도 소용없다. */
    public static function workerOnline(): bool
    {
        return Cache::get(self::WORKER_SEEN_KEY) !== null;
    }

    /**
     * 이 작업이 끝날 때까지 기다린다(수동 순위체크는 사람이 화면에서 결과를 본다).
     * 워커가 없으면 기다리지 않고 즉시 돌아온다 — 헛되이 붙잡고 있으면 요청 처리 슬롯만 먹는다.
     *
     * @return self|null 끝났으면 그 작업, 시간 안에 못 끝냈으면 null
     */
    public function waitForResult(int $seconds = 40, int $pollMs = 500): ?self
    {
        if (! self::workerOnline()) {
            return null;
        }

        $deadline = microtime(true) + max(1, $seconds);
        do {
            usleep($pollMs * 1000);
            $fresh = $this->fresh();
            if (! $fresh || in_array($fresh->status, ['done', 'failed'], true)) {
                return $fresh;
            }
        } while (microtime(true) < $deadline);

        return null;
    }

    public static function newToken(): string
    {
        return Str::random(40);
    }

    /** 이 작업의 매칭 대상(순위 판정 입력). */
    public function target(): array
    {
        return [
            'type' => (string) $this->target_type,
            'product_id' => (string) $this->product_id,
            'id_kind' => (string) $this->id_kind,
            'mall_name' => (string) $this->mall_name,
        ];
    }
}
