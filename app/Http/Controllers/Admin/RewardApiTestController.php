<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Reward\MissionSnapshot;
use App\Domain\Reward\SlotCap;
use App\Http\Controllers\Controller;
use App\Models\RewardMedia;
use App\Models\RewardMission;
use App\Support\RewardDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 미션 API 테스트(운영자 전용) — 매체가 보게 될 응답을 그대로 확인한다.
 * 실제 엔드포인트를 HTTP 로 호출하므로 인증·한도·문구까지 실제와 같다(가짜 응답을 만들지 않는다).
 */
class RewardApiTestController extends Controller
{
    public function index(Request $request)
    {
        $day = RewardDay::current();
        $slotNo = SlotCap::slotNo();

        return view('admin.reward.api-test', [
            'day' => $day,
            'slotNo' => $slotNo,
            'closed' => $slotNo === null,
            'vendorMedia' => RewardMedia::query()->where('type', RewardMedia::TYPE_VENDOR_API)
                ->orderBy('name')->get(['id', 'slug', 'name', 'is_active', 'api_user_id', 'verify_mode']),
            'miniappMedia' => RewardMedia::query()->where('type', RewardMedia::TYPE_MINIAPP)
                ->orderBy('name')->get(['id', 'slug', 'name', 'is_active']),
            'activeMissions' => RewardMission::query()->where('status', 'active')
                ->orderByDesc('id')->limit(20)->get(['id', 'title', 'daily_quota', 'landing_url']),
            'poolVendorId' => (int) config('reward.pool_vendor_id'),
            'answerSource' => (string) config('reward.answer_source'),
        ]);
    }

    /**
     * 실제 엔드포인트 호출 — 응답 본문·상태·헤더를 그대로 돌려준다.
     * 정답은 화면에 노출하지 않는다(운영자 화면이라도 로그·캐시에 남기지 않는다).
     */
    public function call(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', 'in:miniapp,vendor'],
            'endpoint' => ['required', 'string', 'max:40'],
            'user_key' => ['nullable', 'string', 'max:128'],
            'mission_id' => ['nullable', 'integer'],
            'answer' => ['nullable', 'string', 'max:200'],
            'media_slug' => ['nullable', 'string', 'max:60'],
            'api_key' => ['nullable', 'string', 'max:120'],
        ]);

        $base = rtrim($request->getSchemeAndHttpHost(), '/');
        $key = trim((string) ($data['user_key'] ?? '')) ?: 'admin-test-'.substr(sha1((string) $request->user()?->id), 0, 8);

        [$method, $path, $body, $headers] = $this->resolve($data, $key);

        try {
            $res = $this->dispatchInternally($base.$path, $method, $body, $headers);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage(), 'request' => compact('method', 'path', 'body')]);
        }

        $raw = $res->getContent();

        return response()->json([
            'ok' => true,
            'request' => ['method' => $method, 'url' => $base.$path, 'body' => $body,
                'headers' => array_diff_key($headers, ['Authorization' => ''])],
            'status' => $res->getStatusCode(),
            'headers' => array_filter([
                'ETag' => $res->headers->get('ETag'),
                'Retry-After' => $res->headers->get('Retry-After'),
                'Cache-Control' => $res->headers->get('Cache-Control'),
            ]),
            'body' => json_decode((string) $raw, true) ?? ($raw === '' ? null : $raw),
        ]);
    }

    /**
     * 같은 앱에 서브요청을 태운다 — 미들웨어(인증·레이트리밋)를 그대로 지나므로 응답은 실제와 같다.
     * HTTP 로 자기 자신을 부르지 않는 이유: `artisan serve` 는 단일 프로세스라 자기호출이 데드락 난다.
     */
    private function dispatchInternally(string $url, string $method, array $body, array $headers): \Symfony\Component\HttpFoundation\Response
    {
        $sub = Request::create($url, $method, [], [], [],
            ['REMOTE_ADDR' => request()->ip()],
            $method === 'GET' ? null : json_encode($body, JSON_UNESCAPED_UNICODE));

        foreach ($headers as $k => $v) {
            $sub->headers->set($k, $v);
        }
        $sub->headers->set('Accept', 'application/json');
        if ($method !== 'GET') {
            $sub->headers->set('Content-Type', 'application/json');
        }

        $original = request();
        try {
            return app()->handle($sub);
        } finally {
            app()->instance('request', $original);   // 현재 요청 컨텍스트를 되돌린다
        }
    }

    /** @return array{0:string,1:string,2:array,3:array} [method, path, body, headers] */
    private function resolve(array $d, string $key): array
    {
        $mission = (int) ($d['mission_id'] ?? 0);

        if ($d['channel'] === 'miniapp') {
            $headers = ['x-user-key' => $key, 'Accept' => 'application/json'];

            return match ($d['endpoint']) {
                'config' => ['GET', '/api/farm/config', [], $headers],
                'missions' => ['GET', '/api/farm/missions', [], $headers],
                'assign' => ['POST', '/api/farm/missions/assign', [], $headers],
                'plant' => ['POST', '/api/farm/plots/0/plant', ['cropId' => 'lettuce'], $headers],
                'state' => ['GET', '/api/farm/me/state', [], $headers],
                'submit' => ['POST', "/api/farm/missions/{$mission}/submit", ['answer' => (string) ($d['answer'] ?? '')], $headers],
                default => ['GET', '/api/farm/config', [], $headers],
            };
        }

        $headers = ['Authorization' => 'Bearer '.trim((string) ($d['api_key'] ?? '')), 'Accept' => 'application/json'];

        return match ($d['endpoint']) {
            'missions' => ['GET', '/api/v1/missions?participant_hash='.urlencode($key), [], $headers],
            'assign' => ['POST', '/api/v1/missions/assign', ['participant_hash' => $key], $headers],
            'show' => ['GET', "/api/v1/missions/{$mission}?participant_hash=".urlencode($key), [], $headers],
            'participations' => ['GET', '/api/v1/participations?date='.RewardDay::current(), [], $headers],
            'submit' => ['POST', "/api/v1/missions/{$mission}/participations",
                ['participant_hash' => $key, 'answer' => (string) ($d['answer'] ?? '')],
                $headers + ['Idempotency-Key' => 'admin-test-'.bin2hex(random_bytes(8))]],
            default => ['GET', '/api/v1/missions', [], $headers],
        };
    }

    /**
     * 정답 확인(운영자 전용) — 태그형 미션은 참여자마다 번호가 달라, 테스트하려면 그 번호의 태그가 필요하다.
     * 화면에서 명시적으로 눌렀을 때만 응답한다.
     */
    public function answer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mission_id' => ['required', 'integer'],
            'user_key' => ['required', 'string', 'max:128'],
            'channel' => ['required', 'in:miniapp,vendor'],
        ]);

        $m = RewardMission::query()->find($data['mission_id']);
        if (! $m) {
            return response()->json(['ok' => false, 'message' => '미션을 찾을 수 없습니다.'], 404);
        }

        $tags = array_values(array_filter((array) $m->tags, fn ($t) => is_string($t) && trim($t) !== ''));
        $hash = hash('sha256', $data['user_key']);
        $day = RewardDay::current();

        if ($tags === []) {
            return response()->json(['ok' => true, 'source' => $m->answer_type ?: config('reward.answer_source'),
                'answer' => $m->answer, 'note' => '태그가 없는 미션입니다.']);
        }

        $idx = \App\Domain\Reward\TagIndex::for($hash, $m->id, $day, count($tags));

        return response()->json([
            'ok' => true, 'source' => 'tag', 'tagIndex' => $idx, 'tagCount' => count($tags),
            'answer' => $tags[$idx - 1],
            'note' => '이 참여자 키에 대한 정답입니다. 키가 바뀌면 번호도 바뀝니다.',
        ]);
    }

    /** 현재 노출 상태 요약 — 미션이 안 보일 때 원인을 빨리 찾기 위한 것 */
    public function status(): JsonResponse
    {
        $day = RewardDay::current();
        $slotNo = SlotCap::slotNo();

        $rows = $slotNo === null ? [] : app(MissionSnapshot::class)->cachedList($day, $slotNo);
        $exposed = collect($rows)->filter(fn ($m) => (int) $m['used']
            < min(SlotCap::at((int) $m['daily_quota'], $slotNo), (int) $m['daily_quota']))->count();

        return response()->json([
            'day' => $day,
            'slot' => $slotNo,
            'closed' => $slotNo === null,
            'missions' => DB::table('reward_missions')->select('status', DB::raw('COUNT(*) as c'))
                ->groupBy('status')->pluck('c', 'status'),
            'snapshot_rows' => count($rows),
            'exposed_now' => $exposed,
            'pool_vendor_id' => (int) config('reward.pool_vendor_id'),
            'answer_source' => (string) config('reward.answer_source'),
        ]);
    }
}
