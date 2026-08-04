<?php

namespace App\Domain\Shopping;

use App\Models\ShopRankJob;
use App\Models\ShopRankRecord;
use App\Models\ShopRankSlot;
use App\Models\User;
use DomainException;
use Illuminate\Support\Str;

/**
 * 쇼핑 순위추적 슬롯 오케스트레이션 — Place\RankSlotService 미러.
 * 대상(상품 URL/업체명) × 키워드 슬롯 생성·조회·일별 순위 실행.
 */
class ShopRankSlotService
{
    /** 차단(429 로 전 키 소진) 시 기록 rank 센티널. */
    public const RANK_BLOCKED = -1;

    public function __construct(
        private NaverShoppingRankService $engine,
        private ShopSerpBrowserCollector $browser,
    ) {}

    /** 상품 URL/업체명 입력 → 대상 파싱(미리보기·저장용). */
    public function resolve(string $input): array
    {
        return $this->engine->resolveTarget($input);
    }

    /**
     * 대상 1개 × 키워드 N개 → 슬롯 N개. 한도 검사 · 중복 스킵.
     *
     * @return array{target:array, created:list<ShopRankSlot>, skipped:list<string>}
     */
    public function addMany(User $user, string $targetInput, array $keywords, ?string $label = null, bool $ignoreLimit = false): array
    {
        $keywords = collect($keywords)->map(fn ($k) => trim((string) $k))->filter()->unique()->values();
        if ($keywords->isEmpty()) {
            throw new DomainException('키워드를 하나 이상 입력하세요.');
        }

        $limit = $user->rankSlotLimit();
        $used = $user->rankSlotsUsedTotal(); // 플레이스+쇼핑 합산(공유 풀)
        if (! $ignoreLimit && $limit >= 0 && $used + $keywords->count() > $limit) {
            throw new DomainException("슬롯 한도를 초과합니다 (사용 {$used} / 한도 {$limit}, 플레이스+쇼핑 합산).");
        }

        $target = $this->engine->resolveTarget($targetInput);
        if ($target['product_id'] === '' && $target['mall_name'] === '') {
            throw new DomainException('상품 URL(스마트스토어/가격비교) 또는 업체명을 확인하세요.');
        }

        $created = [];
        $skipped = [];
        foreach ($keywords as $kw) {
            $dup = ShopRankSlot::where('user_id', $user->id)->where('keyword', $kw)
                ->where(fn ($q) => $target['product_id'] !== ''
                    ? $q->where('product_id', $target['product_id'])
                    : $q->where('mall_name', $target['mall_name']))
                ->exists();
            if ($dup) {
                $skipped[] = $kw;

                continue;
            }
            $created[] = ShopRankSlot::create([
                'user_id' => $user->id,
                'keyword' => $kw,
                'target_type' => $target['type'],
                'product_id' => $target['product_id'] ?: null,
                'mall_name' => $target['mall_name'] ?: null,
                'product_url' => $target['url'] ?: null,
                'label' => $label ?: null,
                'share_token' => Str::random(32),
                'is_active' => true,
            ]);
        }

        return ['target' => $target, 'created' => $created, 'skipped' => $skipped];
    }

    /** 단건 추가(API 호환). 중복이면 예외. */
    public function add(User $user, string $keyword, string $target, ?string $label = null): ShopRankSlot
    {
        $r = $this->addMany($user, $target, [$keyword], $label);
        if (! count($r['created'])) {
            throw new DomainException('이미 추적 중인 키워드입니다.');
        }

        return $r['created'][0];
    }

    /**
     * 슬롯 1개 순위 조회 + 일별 기록 저장(멱등).
     *
     * @param  bool  $deep  20위 밖을 서버 브라우저로 끝까지 본다(일 2회 배치 전용).
     *                      확장이 안 켜져 있어도 완결되지만 키워드당 20~30초 걸리므로 실시간에는 쓰지 않는다.
     */
    public function run(ShopRankSlot $slot, bool $deep = false): array
    {
        $source = (string) config('rankfree.shopping.rank_source', 'extension');

        if ($source === 'extension') {
            // 상위 20위는 서버가 slot API 1콜(약 0.3초)로 즉시 끝낸다 — 확장 큐를 태우지 않는다.
            $quick = config('rankfree.shopping.quick_top20', true) ? $this->browser->quickCheck($slot) : null;
            if ($quick === null) {
                // 20위 밖 — 배치면 서버 브라우저가 끝까지 보고(무인), 실시간이면 확장 워커에 맡긴다
                // (확장은 실사용자 브라우저·IP 라 훨씬 빠르지만 PC 가 켜져 있어야 한다).
                return $deep ? $this->storeResult($slot, $this->browser->checkRank($slot)) : $this->runViaWorker($slot);
            }
            $res = $quick;
        } else {
            // server = 서버 브라우저 수집. 깊은 순위는 20초 이상 걸려 실사용에는 권하지 않는다.
            $res = $source === 'server'
                ? $this->browser->checkRank($slot)
                : $this->engine->checkRank($slot->keyword, [
                    'type' => $slot->target_type,
                    'product_id' => (string) $slot->product_id,
                    'mall_name' => (string) $slot->mall_name,
                    'url' => (string) $slot->product_url,
                ]);
        }

        return $this->storeResult($slot, $res);
    }

    /**
     * 순위 조회 결과를 일별 기록·슬롯에 반영한다(멱등).
     *
     * @param  array  $res  checkRank 형태(blocked/found/rank/total/price/title/mall_name/product_id)
     */
    private function storeResult(ShopRankSlot $slot, array $res): array
    {
        $rank = ($res['blocked'] && ! $res['found']) ? self::RANK_BLOCKED : (int) $res['rank'];

        // 차단(전 키 429)이라도 오늘 이미 유효 순위가 기록돼 있으면 -1 로 덮지 않는다 —
        // 매시간 재확인 중 한도가 소진돼도 그날의 정상 데이터를 보존.
        if ($rank === self::RANK_BLOCKED) {
            $kept = ShopRankRecord::where('slot_id', $slot->id)
                ->where('checked_date', now()->toDateString())
                ->where('rank', '>', 0)->first();
            if ($kept) {
                $slot->last_checked_at = now();
                $slot->save();

                return $res + ['stored_rank' => (int) $kept->rank];
            }
        }

        ShopRankRecord::updateOrCreate(
            ['slot_id' => $slot->id, 'checked_date' => now()->toDateString()],
            ['rank' => $rank, 'price' => $res['price'] ?: null, 'list_total' => (int) ($res['total'] ?? 0), 'created_at' => now()],
        );

        $slot->last_rank = $rank;
        $slot->last_price = $res['price'] ?: null;
        $slot->last_checked_at = now();
        if (($res['product_id'] ?? '') !== '' && ! $slot->product_id) {
            $slot->product_id = $res['product_id'];
        }
        if (($res['title'] ?? '') !== '' && ! $slot->product_title) {
            $slot->product_title = $res['title'];
        }
        if (($res['mall_name'] ?? '') !== '' && ! $slot->mall_name) {
            $slot->mall_name = $res['mall_name'];
        }
        $slot->save();

        // 3일 연속 미노출(순위 0 = track_depth 위 밖) 자동 중단(2026-07-24) — 트래픽 부담만 늘어 체크 중지.
        // 삭제 아님 — 목록 [재개] 버튼으로 다시 켤 수 있다. 차단(-1) 기록은 판정에 안 쓴다.
        if ($rank === 0 && $slot->is_active) {
            $recent = ShopRankRecord::where('slot_id', $slot->id)->where('rank', '>=', 0)
                ->orderByDesc('checked_date')->limit(3)->pluck('rank');
            if ($recent->count() === 3 && $recent->every(fn ($v) => (int) $v === 0)) {
                $slot->update(['is_active' => false]);
            }
        }

        return $res + ['stored_rank' => $rank];
    }

    /**
     * 확장 워커에 맡기고 **결과가 올 때까지 기다렸다 돌려준다** — 수동 체크는 사람이 화면에서 결과를 본다.
     * 던져만 놓으면 처리가 됐는지 안 됐는지 알 수 없다는 게 실사용에서 드러난 문제였다.
     *
     * 세 갈래로 정직하게 나눈다: 결과 도착 / 확장 꺼짐 / 시간 초과(계속 진행 중).
     */
    private function runViaWorker(ShopRankSlot $slot): array
    {
        $job = $this->enqueue($slot);

        $base = [
            'blocked' => false, 'found' => false, 'rank' => 0, 'total' => 0,
            'job_id' => (int) $job->id, 'product_id' => (string) $slot->product_id,
            'title' => '', 'mall_name' => (string) $slot->mall_name, 'price' => 0, 'link' => '', 'image' => '',
        ];

        if (! ShopRankJob::workerOnline()) {
            // 켜진 PC 가 없다 — 기다려도 소용없고, 작업은 큐에 남아 나중에 처리된다
            return $base + ['queued' => true, 'no_worker' => true];
        }

        $done = $job->waitForResult((int) config('rankfree.shopping.worker_wait_sec', 40));

        if (! $done) {
            return $base + ['queued' => true, 'pending' => true];   // 아직 처리 중
        }
        if ($done->status === 'failed') {
            return $base + ['error' => (string) ($done->error ?: 'worker_failed')];
        }

        return [
            'blocked' => false,
            'found' => (bool) $done->found,
            'rank' => (int) $done->rank,
            'ad' => (bool) $done->ad_exposed,
            'total' => (int) $done->list_total,
            'job_id' => (int) $done->id,
            'product_id' => (string) ($done->product_id ?: $slot->product_id),
            'title' => (string) $done->title,
            'mall_name' => (string) ($done->mall_name ?: $slot->mall_name),
            'price' => (int) $done->price,
            'link' => (string) $done->link,
            'image' => (string) $done->image,
            'stored_rank' => (int) $done->rank,
        ];
    }

    /**
     * 확장 워커에게 순위체크를 맡긴다(2026-08-03) — shop.json 종료 후의 기본 경로.
     * 같은 슬롯에 대기 중인 작업이 있으면 새로 만들지 않는다(폴링·중복 클릭으로 큐가 불어나지 않게).
     */
    public function enqueue(ShopRankSlot $slot, ?int $pages = null): ShopRankJob
    {
        // 서버 배치와 같은 깊이(track_depth)로 맞춘다 — 실시간(확장)과 배치가 다른 깊이를 보면
        // 같은 슬롯의 순위가 경로에 따라 달라지고 '미노출' 판정도 어긋난다. 1페이지 = 80개.
        $pages ??= (int) ceil((int) config('rankfree.shopping.track_depth', 400) / 80);

        $pending = ShopRankJob::where('slot_id', $slot->id)
            ->whereIn('status', ['pending', 'claimed'])->first();
        if ($pending) {
            return $pending;
        }

        // 슬롯에는 id_kind 컬럼이 없다 — 저장된 상품 URL 에서 다시 파생한다
        // (가격비교 /catalog/{nvMid} 와 스마트스토어 channelProductId 는 매칭 필드가 다르다).
        $idKind = 'channel';
        if ((string) $slot->product_url !== '') {
            $idKind = (string) ($this->engine->resolveTarget((string) $slot->product_url)['id_kind'] ?? 'channel');
        }

        return ShopRankJob::create([
            'keyword' => (string) $slot->keyword,
            'target_type' => (string) $slot->target_type,
            'product_id' => (string) $slot->product_id,
            'id_kind' => $idKind,
            'mall_name' => (string) $slot->mall_name,
            'pages' => max(1, $pages),
            'source' => 'slot',
            'slot_id' => $slot->id,
            'user_id' => $slot->user_id,
        ]);
    }

    /**
     * 워커가 돌려준 결과를 슬롯·일별기록에 반영한다. run() 의 저장부와 같은 규칙.
     * 슬롯 작업이 아니면(게스트 조회 등) 아무것도 하지 않는다.
     */
    public function applyJobResult(ShopRankJob $job): void
    {
        if ($job->source !== 'slot' || ! $job->slot_id) {
            return;
        }
        $slot = ShopRankSlot::find($job->slot_id);
        if (! $slot) {
            return;
        }

        $rank = (int) $job->rank;

        ShopRankRecord::updateOrCreate(
            ['slot_id' => $slot->id, 'checked_date' => now()->toDateString()],
            ['rank' => $rank, 'price' => $job->price ?: null, 'list_total' => (int) $job->list_total, 'created_at' => now()],
        );

        $slot->last_rank = $rank;
        $slot->last_price = $job->price ?: null;
        $slot->last_checked_at = now();
        if ((string) $job->title !== '' && ! $slot->product_title) {
            $slot->product_title = $job->title;
        }
        $slot->save();
    }
}
