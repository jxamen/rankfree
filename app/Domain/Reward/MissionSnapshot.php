<?php

namespace App\Domain\Reward;

use App\Models\RewardMission;
use App\Support\RewardDay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 노출 스냅샷(design-01 §2-5·§1-3) — 노출 후보 5조건 쿼리를 요청마다 돌리지 않고
 * 배치가 reward_mission_snapshots(DB 원장, 전 서버 공유)에 굽는다. 정답 계열은 절대 싣지 않는다.
 * 읽기: C1 캐시(L1→L2) → 스냅샷(DB 1행) → 실패 시 파일 폴백(§7-6) → 최후 라이브 쿼리.
 */
class MissionSnapshot
{
    public const SLOT_KEY = 'active';

    /**
     * 노출 후보를 스냅샷 테이블에 굽는다 — reward:build-snapshot(매분)·동기화 직후 호출.
     *
     * @return array<int, array<string, mixed>> 방금 구운 행(호출부가 파일을 굽거나 캐시를 데울 때 그대로 쓴다)
     */
    public function build(): array
    {
        $rows = $this->liveRows();

        DB::table('reward_mission_snapshots')->updateOrInsert(
            ['slot_key' => self::SLOT_KEY],
            ['payload' => json_encode($rows, JSON_UNESCAPED_UNICODE), 'item_count' => count($rows),
                'built_for_day' => RewardDay::current(), 'built_at' => now(), 'updated_at' => now()],
        );

        return $rows;
    }

    /**
     * 공용 목록(개인화 전, C1) — 요청 경로 진입점.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cachedList(string $day, int $slotNo): array
    {
        $key = sprintf('reward:ml:%s:%d:v%d', $day, $slotNo, RewardCache::version());

        $rows = RewardCache::remember($key,
            (int) config('reward.cache.ttl.mission_list_l1'),
            (int) config('reward.cache.ttl.mission_list_l2'),
            function () use ($day) {
                try {
                    $row = DB::table('reward_mission_snapshots')->where('slot_key', self::SLOT_KEY)->first();
                    // 농장일 경계(06:00) 직후엔 직전 날 기준으로 구운 스냅샷이 남아 있다 — 그대로 쓰면
                    // 오늘 시작하는 미션이 안 보이고 어제 끝난 미션이 보인다. 날짜가 다르면 다시 굽는다.
                    if ($row && ($row->built_for_day === null || (string) $row->built_for_day === $day)) {
                        return json_decode((string) $row->payload, true) ?: [];
                    }

                    return $this->build();
                } catch (\Throwable $e) {
                    Log::warning('reward.snapshot: DB 실패 — 파일 폴백. '.$e->getMessage());

                    // 실패 결과(특히 빈 목록)는 캐시하지 않는다 — null 을 돌려주면 remember 가 저장을 건너뛴다
                    return $this->readFile();
                }
            });

        return $rows ?? [];
    }

    /** 파일 캐시(§7-6, 최후 폴백) — reward:warm-cache 가 서버마다 매분 굽는다 */
    public function writeFile(array $rows): string
    {
        $dir = storage_path('app/reward');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir.'/missions-'.RewardDay::current().'.json';
        file_put_contents($path, json_encode($rows, JSON_UNESCAPED_UNICODE), LOCK_EX);

        return $path;
    }

    public function readFile(): ?array
    {
        $day = RewardDay::current();

        // 농장일이 막 바뀐 직후엔 오늘 파일이 아직 없다(워밍은 매분) — 전날 파일이라도 빈 목록보다 낫다
        foreach ([$day, \Illuminate\Support\Carbon::parse($day)->subDay()->toDateString()] as $d) {
            $path = storage_path('app/reward/missions-'.$d.'.json');
            if (is_file($path) && ($rows = json_decode((string) file_get_contents($path), true))) {
                return $rows;
            }
        }

        return null;
    }

    /** 노출 후보 5조건(design-01 §1-3) — 배치·미스 시에만 실행되는 라이브 쿼리 */
    private function liveRows(): array
    {
        $day = RewardDay::current();

        return RewardMission::query()
            ->leftJoin('reward_mission_daily_counters as c', fn ($j) => $j
                ->on('c.mission_id', '=', 'reward_missions.id')->where('c.stat_date', $day))
            ->where('reward_missions.status', 'active')
            // 상품을 특정할 수 없으면 참여가 불가능하다 — 상품명·검색어가 빠진 안내로는 상품을 찾을 수 없다.
            ->whereNotNull('reward_missions.product_title')->where('reward_missions.product_title', '<>', '')
            ->whereNotNull('reward_missions.keyword')->where('reward_missions.keyword', '<>', '')
            ->whereDate('starts_on', '<=', $day)->whereDate('ends_on', '>=', $day)
            ->whereRaw('COALESCE(c.used, 0) < COALESCE(c.daily_quota, reward_missions.daily_quota)')
            ->orderBy('sort_order')->orderBy('reward_missions.id')
            ->select('reward_missions.*', DB::raw('COALESCE(c.used, 0) as today_used'))
            ->limit(200)
            ->get()
            ->map(function (RewardMission $m) {
                $tags = array_values(array_filter((array) $m->tags, fn ($t) => is_string($t) && trim($t) !== ''));

                // 공용(개인화 전) 필드만 — tagIndex 는 읽기 시점에 사용자별로 계산, 정답·태그 원문은 제외
                return [
                    'id' => $m->id,
                    'kind' => $m->kind,
                    'title' => $m->title,
                    'description' => $m->description,
                    'keyword' => $m->keyword,
                    'reward_item' => $m->reward_item,
                    'reward_count' => (int) $m->reward_count,
                    'payout_point' => (int) $m->payout_point,
                    'product_title' => $m->product_title,
                    'product_emoji' => $m->product_emoji,
                    'product_image_url' => $m->product_image_url,
                    'product_price' => $m->product_price,
                    'shop_name' => $m->shop_name,
                    'landing_url' => $m->landing_url ?: $m->product_url,
                    'guide' => $m->guide,
                    'question' => $m->question,
                    'placeholder' => $m->placeholder,
                    'tag_count' => count($tags),
                    'per_user_limit' => (int) $m->per_user_limit,
                    'per_user_daily_limit' => (int) $m->per_user_daily_limit,
                    'daily_quota' => (int) $m->daily_quota,
                    'used' => (int) $m->today_used,
                    'starts_on' => $m->starts_on->toDateString(),
                    'ends_on' => $m->ends_on->toDateString(),
                ];
            })
            ->values()
            ->all();
    }
}
