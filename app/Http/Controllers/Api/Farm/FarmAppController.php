<?php

namespace App\Http\Controllers\Api\Farm;

use App\Domain\Reward\MissionAssigner;
use App\Domain\Reward\MissionSnapshot;
use App\Domain\Reward\MissionSubmitService;
use App\Domain\Reward\SlotCap;
use App\Domain\Reward\TagIndex;
use App\Http\Controllers\Controller;
use App\Models\FarmCrop;
use App\Models\FarmPlanting;
use App\Support\RewardDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 퀴즈농장(miniapp) API — server-api-spec.md 계약. 클라이언트(farm-quiz)가 이미 이 형태를 호출한다.
 * 정답 계열(answer·tags)은 어떤 응답에도 싣지 않는다. 판정은 전부 서버(MissionSubmitService)가 다시 한다.
 */
class FarmAppController extends Controller
{
    public function config(Request $request): JsonResponse
    {
        $media = $request->attributes->get('rewardMedia');

        $pointsByCrop = FarmCrop::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->pluck('points', 'code');

        return response()->json([
            'cooldownMinutes' => (int) $media->setting('cooldown_minutes'),
            'dailyMissionLimit' => (int) $media->setting('daily_mission_limit'),
            'pointsByCrop' => $pointsByCrop,
            'defaultPoints' => (int) $media->setting('default_points'),
            'maxPointPerUser' => (int) $media->setting('point_cap'),
        ]);
    }

    /** 오늘의 미션 목록 — 노출 5조건(design-01 §1-3) + 슬롯 잔여(design-04 §7) + 사용자별 제외·tagIndex */
    public function missions(Request $request): JsonResponse
    {
        $media = $request->attributes->get('rewardMedia');
        $user = $request->attributes->get('rewardUser');
        $day = RewardDay::current();
        $slotNo = SlotCap::slotNo();

        if ($slotNo === null) {
            // 심야 휴지(02~06) — 노출 중단(design-02 §6-4)
            return response()->json(['missions' => [], 'meta' => [
                'closed' => true,
                'opensAt' => RewardDay::start(Carbon::parse($day)->addDay()->toDateString())->toIso8601String(),
            ]]);
        }

        // 공용 목록은 C1 캐시(스냅샷 원장 — Phase 4). 슬롯 잔여를 넘긴 미션은 숨긴다(소진과 동일하게 보임)
        $rows = collect(app(MissionSnapshot::class)->cachedList($day, $slotNo))
            ->filter(fn ($m) => (int) $m['used'] < min(SlotCap::at((int) $m['daily_quota'], $slotNo), (int) $m['daily_quota']));

        // 쿨다운·일 상한 상태(C15 — completed 와 locked 둘 다 내려준다: 숨기면 재방문 트리거가 사라진다)
        $isToday = $user->today_date?->toDateString() === $day;
        $todayCount = $isToday ? (int) $user->today_count : 0;
        $dailyLimit = (int) $media->setting('daily_mission_limit');
        $remaining = max(0, $dailyLimit - $todayCount);
        $cooldownAt = ($user->cooldown_until && $user->cooldown_until->isFuture())
            ? $user->cooldown_until->tz('Asia/Seoul')->toIso8601String() : null;
        $locked = $cooldownAt !== null || $remaining === 0;
        $lockReason = $cooldownAt !== null ? 'cooldown' : ($remaining === 0 ? 'daily_limit' : null);

        // 잠금 상태면 어차피 제출이 불가하므로 사용자별 제외 조회를 생략 — 쿨다운 중 요청이 인증 쿼리로 끝난다(Phase 4)
        if (! $locked && $rows->isNotEmpty()) {
            $counters = DB::table('reward_user_mission_counters')
                ->where('reward_user_id', $user->id)->whereIn('mission_id', $rows->pluck('id'))
                ->get()->keyBy('mission_id');
            $rows = $rows->reject(function ($m) use ($counters, $day) {
                $c = $counters[$m['id']] ?? null;

                return $c && ((int) $c->done_count >= (int) $m['per_user_limit']
                    || ($c->last_done_on === $day && (int) $c->today_count >= (int) $m['per_user_daily_limit']));
            });
        }
        $rows = $rows->take((int) config('reward.exposure_limit'));

        $missions = $rows->values()->map(fn (array $m) => $this->missionPayload($m, $user->user_key_hash, $day, $locked));

        return response()->json([
            'missions' => $missions,
            'meta' => array_filter([
                'remaining' => $remaining,
                'dailyLimit' => $dailyLimit,
                'locked' => $locked,
                'lockReason' => $lockReason,
                'unlockAt' => $cooldownAt,
                'cooldownUntil' => $cooldownAt,
                'slot' => $slotNo,
                'closed' => false,
            ], fn ($v) => $v !== null),
        ]);
    }

    /**
     * 단건 할당(design-04 §3-0) — "미션 참여하기"를 누르면 서버가 미션 하나를 골라 내려준다.
     * 목록(missions)과 같은 미션 객체 형태라 클라이언트가 렌더링 코드를 공유할 수 있다.
     * 후보가 없으면 mission=null + 사유 — 소프트 차단(§8)은 사유를 노출하지 않고 "없음"으로 뭉갠다.
     */
    public function assign(Request $request): JsonResponse
    {
        $media = $request->attributes->get('rewardMedia');
        $user = $request->attributes->get('rewardUser');
        $day = RewardDay::current();
        $slotNo = SlotCap::slotNo();

        if ($slotNo === null) {
            return response()->json(['mission' => null, 'meta' => [
                'closed' => true, 'reason' => 'closed',
                'opensAt' => RewardDay::start(Carbon::parse($day)->addDay()->toDateString())->toIso8601String(),
            ]]);
        }

        // 사용자 상태 — 쿨다운·일 상한은 사유를 알려준다(다음 시각 안내가 재방문 트리거다).
        // 차단(blocked)은 사유를 주지 않는다: 어뷰저에게 판정 정보를 주지 않는다(§8)
        $isToday = $user->today_date?->toDateString() === $day;
        $dailyLimit = (int) $media->setting('daily_mission_limit');
        $remaining = max(0, $dailyLimit - ($isToday ? (int) $user->today_count : 0));
        $cooldownAt = ($user->cooldown_until && $user->cooldown_until->isFuture())
            ? $user->cooldown_until->tz('Asia/Seoul')->toIso8601String() : null;

        $reason = match (true) {
            $user->status !== 'active' => 'no_mission',
            $cooldownAt !== null => 'cooldown',
            $remaining === 0 => 'daily_limit',
            default => null,
        };

        $picked = $reason === null
            ? app(MissionAssigner::class)->pick($user->id, $user->user_key_hash, $day, $slotNo)
            : null;

        return response()->json([
            'mission' => $picked ? $this->missionPayload($picked, $user->user_key_hash, $day, false) : null,
            'meta' => array_filter([
                'reason' => $picked ? null : ($reason ?? 'no_mission'),
                'remaining' => $remaining,
                'dailyLimit' => $dailyLimit,
                'unlockAt' => $cooldownAt,
                'cooldownUntil' => $cooldownAt,
                'slot' => $slotNo,
                'closed' => false,
            ], fn ($v) => $v !== null),
        ]);
    }

    /** 스냅샷 행 → 미션 응답 객체(목록·단건 공용). 정답·태그 목록은 싣지 않는다 — tagIndex/tagCount 만 */
    private function missionPayload(array $m, string $userKeyHash, string $day, bool $locked): array
    {
        $tagCount = (int) $m['tag_count'];
        $tagIndex = $tagCount > 0 ? TagIndex::for($userKeyHash, (int) $m['id'], $day, $tagCount) : null;

        $quiz = [
            'product' => [
                'name' => (string) ($m['product_title'] ?? ''),
                'imageEmoji' => $m['product_emoji'],
                'imageUrl' => $m['product_image_url'],
                'price' => $m['product_price'],
            ],
            'guide' => $m['guide'] ?: [
                "아래 '참여하기'를 누르면 상품 페이지가 열려요.",
                $tagIndex ? "상품 정보에 있는 해시태그 중 {$tagIndex}번째 태그를 확인해 주세요." : '상품 정보를 확인해 주세요.',
                '다시 돌아와서 입력하면 돼요. #은 빼고 적어도 괜찮아요.',
            ],
            'hintUrl' => $m['landing_url'],
            'question' => $m['question'] ?: ($tagIndex ? "{$tagIndex}번째 해시태그를 입력해 주세요" : '정답을 입력해 주세요'),
            'placeholder' => $m['placeholder'],
        ];
        if ($tagIndex !== null) {
            $quiz['tagIndex'] = $tagIndex;      // 사용자마다 다른 결정적 번호 — 태그 목록은 절대 미노출
            $quiz['tagCount'] = $tagCount;
        }

        return [
            'id' => (string) $m['id'],
            'kind' => $m['kind'],
            'title' => $m['title'],
            'description' => $m['description'],
            'reward' => ['item' => $m['reward_item'], 'count' => (int) $m['reward_count']],
            'points' => (int) $m['payout_point'],
            'quiz' => $quiz,
            'completed' => $locked,             // C15 — 구클라 잠금 UI 호환
            'locked' => $locked,
        ];
    }

    /** 정답 제출 — 판정 전체가 MissionSubmitService(A 게이트 → B 채점 → C 확정 트랜잭션) */
    public function submit(Request $request, int $mission): JsonResponse
    {
        $data = $request->validate(['answer' => 'required|string|max:200']);

        $payload = app(MissionSubmitService::class)->submit(
            $request->attributes->get('rewardMedia'),
            $request->attributes->get('rewardUser'),
            $mission,
            (string) $data['answer'],
            $request->ip(),
        );

        return response()->json($payload);
    }

    /** 작물 심기 — reward_points·required_days 는 심는 순간 스냅샷(소급 금지) */
    public function plant(Request $request, int $index): JsonResponse
    {
        abort_unless($index >= 0 && $index <= 2, 404);
        $data = $request->validate(['cropId' => 'required|string|max:20']);

        $user = $request->attributes->get('rewardUser');
        $crop = FarmCrop::query()->where('code', $data['cropId'])->where('is_active', true)->first();
        if (! $crop) {
            return response()->json(['ok' => false, 'message' => '선택할 수 없는 작물이에요.'], 422);
        }

        $occupied = FarmPlanting::query()
            ->where('reward_user_id', $user->id)->where('plot_index', $index)
            ->whereIn('status', ['growing', 'ready'])->exists();
        if ($occupied) {
            return response()->json(['ok' => false, 'message' => '이미 작물이 자라고 있어요.'], 422);
        }

        try {
            FarmPlanting::query()->create([
                'reward_user_id' => $user->id,
                'plot_index' => $index,
                'round_no' => (int) FarmPlanting::query()
                    ->where('reward_user_id', $user->id)->where('plot_index', $index)->max('round_no') + 1,
                'crop_id' => $crop->code,
                'required_days' => (int) $crop->days,
                'reward_points' => (int) $crop->points,   // 심을 때 고정 — 이후 설정 변경에 소급하지 않는다
                'planted_on' => RewardDay::current(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return response()->json(['ok' => false, 'message' => '이미 작물이 자라고 있어요.'], 422);
        }

        return response()->json(['ok' => true]);
    }

    /** 내 농장 상태 — plots 는 위치(0~2) 기반 배열(클라이언트 mergeServerState 가 인덱스로 매핑) */
    public function state(Request $request): JsonResponse
    {
        $user = $request->attributes->get('rewardUser');
        $day = RewardDay::current();

        $plantings = FarmPlanting::query()
            ->where('reward_user_id', $user->id)->whereIn('status', ['growing', 'ready'])
            ->get()->keyBy('plot_index');

        $dates = $plantings->isEmpty() ? collect() : DB::table('reward_participation_logs')
            ->whereIn('planting_id', $plantings->pluck('id'))->where('result', 'correct')
            ->orderBy('id')->get(['planting_id', 'stat_date'])->groupBy('planting_id');

        $plots = collect(range(0, 2))->map(function ($i) use ($plantings, $dates) {
            $p = $plantings[$i] ?? null;
            if (! $p) {
                return ['cropId' => null, 'completedDates' => [], 'lastTendedDate' => ''];
            }

            return [
                'cropId' => $p->crop_id,
                'completedDates' => ($dates[$p->id] ?? collect())->pluck('stat_date')->values(),
                'lastTendedDate' => $p->last_tended_on?->toDateString() ?? '',
                'rewardPoints' => (int) $p->reward_points,   // 심은 시점 고정 금액 — 표시와 지급을 일치시킨다
            ];
        });

        $todayMissionIds = DB::table('reward_user_mission_counters')
            ->where('reward_user_id', $user->id)->where('last_done_on', $day)->where('today_count', '>', 0)
            ->pluck('mission_id')->map(fn ($id) => (string) $id)->values();

        $harvested = FarmPlanting::query()
            ->where('reward_user_id', $user->id)->where('status', 'harvested')
            ->pluck('crop_id')->unique()->values();

        return response()->json([
            'plots' => $plots,
            'todayMissionIds' => $todayMissionIds,
            'nextMissionAt' => $user->cooldown_until?->tz('Asia/Seoul')->toIso8601String(),
            'cooldownNotify' => (bool) $user->cooldown_notify,
            'earnedPoints' => (int) $user->accrued_points,
            'harvested' => $harvested,
        ]);
    }

    public function cooldownNotify(Request $request): JsonResponse
    {
        $enabled = $request->boolean('enabled');

        $request->attributes->get('rewardUser')
            ->forceFill(['cooldown_notify' => $enabled])->save();

        return response()->json(['cooldownNotify' => $enabled]);
    }
}
