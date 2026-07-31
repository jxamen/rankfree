<?php

namespace App\Http\Controllers\Api;

use App\Domain\Reward\MissionAssigner;
use App\Domain\Reward\MissionSnapshot;
use App\Domain\Reward\SlotCap;
use App\Domain\Reward\TagIndex;
use App\Domain\Reward\VendorSubmitService;
use App\Http\Controllers\Controller;
use App\Models\RewardMedia;
use App\Models\RewardMission;
use App\Support\RewardDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 벤더 S2S 미션 API v1 (scope: mission — design-04 §3).
 * 리스트(오퍼월) 방식: 목록 → 클릭 검증+상세 → 참여 제출(멱등키 필수).
 * remaining 은 하루 전체가 아니라 **현재 슬롯 잔여**만 노출한다(§7 — 몰아치기 원천 차단).
 * 정답·태그 목록은 어떤 응답에도 싣지 않는다.
 */
class VendorMissionApiController extends Controller
{
    /** API 키 소유 회원 ↔ vendor_api 매체 매핑 — 매체가 없으면 403 */
    private function media(Request $request): RewardMedia|JsonResponse
    {
        $media = RewardMedia::query()
            ->where('type', RewardMedia::TYPE_VENDOR_API)
            ->where('api_user_id', $request->user()->id)
            ->where('is_active', true)
            ->first();

        return $media ?: response()->json(['message' => '이 계정에 연결된 리워드 매체가 없습니다. 관리자에게 문의하세요.'], 403);
    }

    /** 미션 목록 — 현재 슬롯 잔여가 있는 미션만. ?participant_hash= 를 주면 그 사용자 기준 제외 반영(§8) */
    public function index(Request $request): JsonResponse
    {
        $media = $this->media($request);
        if ($media instanceof JsonResponse) {
            return $media;
        }

        $day = RewardDay::current();
        $slotNo = SlotCap::slotNo();
        if ($slotNo === null) {
            return response()->json(['missions' => [], 'meta' => ['closed' => true,
                'opensAt' => RewardDay::start(\Illuminate\Support\Carbon::parse($day)->addDay()->toDateString())->toIso8601String()]]);
        }

        // 공용 목록은 C1 캐시(스냅샷 원장 — Phase 4). remaining = 현재 슬롯 잔여(§7)
        $rows = collect(app(MissionSnapshot::class)->cachedList($day, $slotNo))
            ->map(function (array $m) use ($slotNo) {
                $m['slot_remaining'] = max(0, min(SlotCap::at((int) $m['daily_quota'], $slotNo), (int) $m['daily_quota']) - (int) $m['used']);

                return $m;
            })
            ->filter(fn (array $m) => $m['slot_remaining'] > 0);   // 슬롯 소진 미션은 숨긴다(소진과 구분 불가)

        // 사용자별 피드(§8) — 소프트 차단이면 빈 목록(오류가 아니라 "없음"), 상한 채운 미션 제외.
        // 읽기 경로에서는 참여자 행을 만들지 않는다(폴링만으로 reward_users 를 무한히 늘릴 수 있다).
        $hash = mb_substr(trim((string) $request->query('participant_hash')), 0, 128);
        if ($hash !== '' && ($user = app(VendorSubmitService::class)->findParticipant($media, $hash))) {
            if ($user->status !== 'active') {
                return response()->json(['missions' => [], 'meta' => ['closed' => false]]);
            }
            $counters = DB::table('reward_user_mission_counters')
                ->where('reward_user_id', $user->id)->whereIn('mission_id', $rows->pluck('id'))
                ->get()->keyBy('mission_id');
            $rows = $rows->reject(function (array $m) use ($counters, $day) {
                $c = $counters[$m['id']] ?? null;

                return $c && ((int) $c->done_count >= (int) $m['per_user_limit']
                    || ($c->last_done_on === $day && (int) $c->today_count >= (int) $m['per_user_daily_limit']));
            });
        }

        $body = [
            'missions' => $rows->values()->map(fn (array $m) => $this->rowPayload($m) + ['remaining' => $m['slot_remaining']]),
            'meta' => ['slot' => $slotNo, 'closed' => false, 'verifyMode' => $media->verify_mode],
        ];

        // ETag + 짧은 캐시(§3) — 벤더 폴링 부하를 304 로 흡수한다. 개인화 피드는 입력에 hash 를 섞는다
        $etag = '"'.md5($hash.'|'.json_encode($body)).'"';
        if (trim((string) $request->headers->get('If-None-Match')) === $etag) {
            return response()->json(null, 304)->withHeaders(['ETag' => $etag]);
        }

        return response()->json($body)
            ->withHeaders(['ETag' => $etag, 'Cache-Control' => 'private, max-age=5']);
    }

    /**
     * 단건 할당(§3-0 "단건 할당" 방식) — 목록을 거치지 않고 참여 가능한 미션 1건을 바로 받는다.
     * 줄 미션이 없으면 204(사유 비공개 — §8). 다음 구간까지의 대기 시간은 Retry-After 헤더로 준다.
     */
    public function assign(Request $request): JsonResponse
    {
        $media = $this->media($request);
        if ($media instanceof JsonResponse) {
            return $media;
        }

        $data = $request->validate(['participant_hash' => 'required|string|max:128']);
        $day = RewardDay::current();
        $slotNo = SlotCap::slotNo();

        if ($slotNo === null) {
            return $this->noContent(SlotCap::secondsToNextSlot());
        }

        $user = app(VendorSubmitService::class)->participant($media, $data['participant_hash']);
        if ($user->status !== 'active') {
            return $this->noContent();   // 소프트 차단 — 정상 소진과 구분되지 않게(§8)
        }

        $picked = app(MissionAssigner::class)->pick($user->id, $user->user_key_hash, $day, $slotNo);
        if (! $picked) {
            return $this->noContent(SlotCap::secondsToNextSlot());
        }

        $mission = RewardMission::query()->find($picked['id']);
        if (! $mission) {
            return $this->noContent();   // 스냅샷과 원장이 어긋난 순간 — 다음 호출에서 정상화된다
        }

        $slotRemaining = max(0, min(SlotCap::at((int) $picked['daily_quota'], $slotNo), (int) $picked['daily_quota'])
            - (int) $picked['used']);
        $payload = $this->missionPayload($mission) + ['remaining' => $slotRemaining];

        $tagCount = (int) $picked['tag_count'];
        if ($tagCount > 0) {
            $payload['quiz'] = [
                'question' => $mission->question ?: '지정된 순서의 해시태그를 입력해 주세요',
                'tagIndex' => TagIndex::for($user->user_key_hash, $mission->id, $day, $tagCount),
                'tagCount' => $tagCount,
            ];
        }

        return response()->json(['status' => 'ok', 'mission' => $payload]);
    }

    /** 줄 미션 없음 — 본문 없이 204. 대기 시간을 알 수 있으면 Retry-After 로 백오프를 돕는다 */
    private function noContent(?int $retryAfter = null): JsonResponse
    {
        return response()->json(null, 204)
            ->withHeaders($retryAfter ? ['Retry-After' => (string) $retryAfter] : []);
    }

    /** 클릭 검증 + 상세(§3-0) — 실존·활성·슬롯 잔여·참여자 자격을 확인하고 통과 시에만 상세를 준다 */
    public function show(Request $request, int $mission): JsonResponse
    {
        $media = $this->media($request);
        if ($media instanceof JsonResponse) {
            return $media;
        }

        $data = $request->validate(['participant_hash' => 'required|string|max:128']);
        $day = RewardDay::current();
        $slotNo = SlotCap::slotNo();
        if ($slotNo === null) {
            return response()->json(['status' => 'rejected', 'reason' => 'closed'], 422);
        }

        $m = RewardMission::query()->find($mission);
        if (! $m) {
            return response()->json(['status' => 'rejected', 'reason' => 'not_found'], 410);
        }
        if ($m->status !== 'active' || $day < $m->starts_on->toDateString() || $day > $m->ends_on->toDateString()) {
            return response()->json(['status' => 'rejected', 'reason' => 'closed'], 422);
        }

        $used = (int) DB::table('reward_mission_daily_counters')
            ->where('mission_id', $m->id)->where('stat_date', $day)->value('used');
        $slotRemaining = max(0, min(SlotCap::at((int) $m->daily_quota, $slotNo), (int) $m->daily_quota) - $used);
        if ($slotRemaining < 1) {
            return response()->json(['status' => 'rejected', 'reason' => 'slot_exhausted'], 422);
        }

        $user = app(VendorSubmitService::class)->participant($media, $data['participant_hash']);
        if ($user->status !== 'active') {
            return response()->json(['status' => 'rejected', 'reason' => 'not_eligible'], 422);   // §8 사유 비공개
        }
        $c = DB::table('reward_user_mission_counters')
            ->where('reward_user_id', $user->id)->where('mission_id', $m->id)->first();
        if ($c && ((int) $c->done_count >= (int) $m->per_user_limit
            || ($c->last_done_on === $day && (int) $c->today_count >= (int) $m->per_user_daily_limit))) {
            return response()->json(['status' => 'rejected', 'reason' => 'participant_duplicate'], 422);
        }

        $payload = $this->missionPayload($m) + ['remaining' => $slotRemaining];
        $tags = array_values(array_filter((array) $m->tags, fn ($t) => is_string($t) && trim($t) !== ''));
        if ($tags !== []) {
            $payload['quiz'] = [
                'question' => $m->question ?: '지정된 순서의 해시태그를 입력해 주세요',
                'tagIndex' => TagIndex::for($user->user_key_hash, $m->id, $day, count($tags)),   // 참여자별 결정적
                'tagCount' => count($tags),
            ];
        }

        return response()->json(['status' => 'ok', 'mission' => $payload]);
    }

    /** 참여 제출 — Idempotency-Key 필수. 응답 규약은 design-04 §3 */
    public function store(Request $request, int $mission): JsonResponse
    {
        $media = $this->media($request);
        if ($media instanceof JsonResponse) {
            return $media;
        }

        $idemKey = trim((string) $request->header('Idempotency-Key'));
        if ($idemKey === '' || mb_strlen($idemKey) > 80) {
            return response()->json(['message' => 'Idempotency-Key 헤더가 필요합니다(최대 80자).'], 400);
        }

        $data = $request->validate([
            'participant_hash' => 'required|string|max:128',
            'answer' => ($media->verify_mode === 'server' ? 'required' : 'nullable').'|string|max:200',
        ]);

        [$payload, $status] = app(VendorSubmitService::class)->submit(
            $media, $mission, $data['participant_hash'], $data['answer'] ?? null, $idemKey, $request->ip());

        return response()->json($payload, $status);
    }

    /** 정산 대사 — 일자별 수락 집계(§3). 상세 정산은 Phase 6 */
    public function participations(Request $request): JsonResponse
    {
        $media = $this->media($request);
        if ($media instanceof JsonResponse) {
            return $media;
        }

        $date = (string) $request->query('date', RewardDay::current());
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date), 422);

        $rows = DB::table('reward_participation_logs')
            ->where('media_id', $media->id)->where('stat_date', $date)->where('result', 'correct')
            ->groupBy('mission_id')
            ->selectRaw('mission_id, COUNT(*) as accepted')
            ->get();

        return response()->json([
            'date' => $date,
            'totalAccepted' => (int) $rows->sum('accepted'),
            'byMission' => $rows->map(fn ($r) => ['missionId' => (string) $r->mission_id, 'accepted' => (int) $r->accepted])->values(),
        ]);
    }

    /** 스냅샷 행(배열) → 벤더 목록 필드 — 정답 계열은 스냅샷에 아예 실리지 않는다 */
    private function rowPayload(array $m): array
    {
        return [
            'id' => (string) $m['id'],
            'title' => $m['title'],
            'description' => $m['description'],
            'keyword' => $m['keyword'],
            'landingUrl' => $m['landing_url'],
            'product' => [
                'name' => (string) ($m['product_title'] ?? ''),
                'imageUrl' => $m['product_image_url'],
                'price' => $m['product_price'],
                'shopName' => $m['shop_name'],
            ],
            'startsOn' => $m['starts_on'],
            'endsOn' => $m['ends_on'],
        ];
    }

    /** 벤더에 내리는 미션 필드 — 정답 계열(answer·tags)은 hidden 이라 직렬화되지 않는다 */
    private function missionPayload(RewardMission $m): array
    {
        return [
            'id' => (string) $m->id,
            'title' => $m->title,
            'description' => $m->description,
            'keyword' => $m->keyword,
            'landingUrl' => $m->landing_url ?: $m->product_url,
            'product' => [
                'name' => (string) ($m->product_title ?? ''),
                'imageUrl' => $m->product_image_url,
                'price' => $m->product_price,
                'shopName' => $m->shop_name,
            ],
            'startsOn' => $m->starts_on->toDateString(),
            'endsOn' => $m->ends_on->toDateString(),
        ];
    }
}
