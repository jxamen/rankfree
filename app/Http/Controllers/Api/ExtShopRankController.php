<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shopping\NaverShoppingRankService;
use App\Domain\Shopping\ShopRankFromProducts;
use App\Domain\Shopping\ShopRankSlotService;
use App\Http\Controllers\Controller;
use App\Models\ShopRankJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 쇼핑 순위체크 워커 API(2026-08-03).
 *
 * 네이버가 openapi shop.json 을 종료하고(공지 32564) 서버 크롤링도 막아(nCaptcha 없으면 418,
 * 구 search/all 은 로그인·캡차) 서버 혼자서는 순위를 못 구한다.
 * → 확장이 켜진 PC 들이 **시장분석 수집기 그대로** 목록을 가져와 여기로 돌려준다.
 *
 * 여러 대가 켜져 있으면 자연히 분산된다(claim 이 원자적이라 같은 작업을 둘이 잡지 않는다).
 */
class ExtShopRankController extends Controller
{
    public function __construct(
        private ShopRankFromProducts $ranker,
        private ShopRankSlotService $slots,
    ) {}

    /**
     * 확장 패널(쇼핑 → 순위체크)의 1회성 판정 — 슬롯·큐를 만들지 않고 결과만 돌려준다.
     *
     * 확장은 목록 수집만 하고 **매칭·순위 계산은 여기서** 한다 — result() 와 같은 판정기를 써
     * 규칙이 두 곳으로 갈라지지 않게 한다(광고 제외 오가닉 순위, 광고 노출은 ad 로 따로).
     */
    public function check(Request $request, NaverShoppingRankService $engine): JsonResponse
    {
        $data = $request->validate([
            'keyword' => 'required|string|max:100',
            'target' => 'required|string|max:1000',   // 상품 URL·상품ID 또는 업체(스토어)명
            'products' => 'required|array|max:2000',
            'products.*' => 'array',
            'total' => 'nullable|integer|min:0',
        ]);

        $raw = trim($data['target']);

        // resolveTarget 은 파싱에 실패한 입력을 **업체명 후보**로 넘긴다 — 엉뚱한 URL 을 붙여넣으면
        // 그 URL 문자열로 업체명 매칭을 하다 조용히 '미노출'이 된다. 입력 단계에서 걸러 알려준다.
        if (preg_match('#^https?://#i', $raw) && ! str_contains(strtolower($raw), 'naver.com')) {
            return response()->json([
                'ok' => false,
                'message' => '네이버 쇼핑 상품 URL이 아닙니다. 스마트스토어·브랜드스토어·가격비교 URL 또는 업체명을 넣어주세요.',
            ], 422);
        }

        $target = $engine->resolveTarget($raw);
        if ((string) ($target['product_id'] ?? '') === '' && (string) ($target['mall_name'] ?? '') === '') {
            return response()->json([
                'ok' => false,
                'message' => '상품 URL(스마트스토어·브랜드스토어·가격비교) 또는 업체명을 확인하세요.',
            ], 422);
        }

        $res = $this->ranker->rank(
            array_values((array) $data['products']),
            $target,
            (int) ($data['total'] ?? 0),
        );

        return response()->json(['ok' => true, 'data' => $res + [
            'keyword' => trim($data['keyword']),
            'target_type' => (string) ($target['type'] ?? ''),
        ]]);
    }

    /**
     * 대상(상품 URL·업체명) 해석만 — 확장이 **수집 조기중단 힌트**로 쓴다.
     * 판정 규칙은 그대로 서버(check)에 있고, 여기서 주는 건 "이걸 찾으면 그만 긁어도 된다"는 신호뿐이다.
     * 확장이 URL 파싱을 따로 구현하지 않게 하려고 서버가 해석해 준다.
     */
    public function resolve(Request $request, NaverShoppingRankService $engine): JsonResponse
    {
        $data = $request->validate(['target' => 'required|string|max:1000']);
        $t = $engine->resolveTarget(trim($data['target']));

        return response()->json(['ok' => true, 'data' => [
            'type' => (string) ($t['type'] ?? 'mall'),
            'product_id' => (string) ($t['product_id'] ?? ''),
            'mall_name' => (string) ($t['mall_name'] ?? ''),
            'id_kind' => (string) ($t['id_kind'] ?? 'channel'),
        ]]);
    }

    /**
     * 작업 가져가기. 줄 게 없으면 빈 배열 — 확장은 그냥 다음 주기에 다시 묻는다.
     * 워커 식별자는 확장 설치 단위로 고정된 값을 보낸다(누가 잡았는지 추적·리스 회수용).
     */
    public function claim(Request $request): JsonResponse
    {
        $data = $request->validate([
            'worker_id' => 'required|string|max:64',
            // 0 = 핑(작업을 가져가지 않고 인증만 확인) — 옵션 화면의 '연결 테스트'가 쓴다.
            // 테스트하려다 작업을 물고 180초 묶어두면 안 되기 때문이다.
            'limit' => 'nullable|integer|min:0|max:5',
            'wait' => 'nullable|integer|min:0|max:60',
        ]);

        // 워커가 살아 있다는 표식 — 요청 화면이 "기다릴 가치가 있는가"를 판단하는 근거다.
        // 아무도 안 켜져 있으면 기다리게 두지 말고 즉시 사실대로 알려야 한다.
        ShopRankJob::touchWorkerSeen((string) $data['worker_id']);

        $workerId = (string) $data['worker_id'];
        $limit = (int) ($data['limit'] ?? 2);

        if ($limit === 0) {
            return response()->json(['data' => [
                'items' => [], 'lease_seconds' => ShopRankJob::LEASE_SECONDS,
                'ping' => true, 'pending' => ShopRankJob::where('status', 'pending')->count(),
            ]]);
        }

        $jobs = ShopRankJob::claim($workerId, $limit);

        /*
         * 롱폴링 — 줄 게 없으면 잠깐 붙잡고 기다린다.
         * 확장 알람은 최소 1분 주기라, 알람만 믿으면 방금 누른 순위체크가 최대 1분 늦게 잡힌다.
         * 여기서 기다려 주면 픽업이 즉시가 되고, 대기 중 fetch 가 MV3 서비스워커도 깨워 둔다.
         */
        // 대기 시간은 서버가 정한다 — 붙잡힌 요청이 처리 슬롯을 먹기 때문이다.
        // ⚠️ `artisan serve` 는 기본 1프로세스라 여기서 붙잡으면 다른 요청이 전부 멈춘다.
        //    로컬에서 쓰려면 PHP_CLI_SERVER_WORKERS=4 로 띄우거나 이 값을 0 으로 둔다.
        $wait = min((int) ($data['wait'] ?? 0), (int) config('rankfree.shopping.worker_longpoll_sec', 20));
        if (! $jobs && $wait > 0) {
            $deadline = microtime(true) + $wait;
            while (! $jobs && microtime(true) < $deadline) {
                usleep(700000);
                ShopRankJob::touchWorkerSeen($workerId);   // 기다리는 동안에도 살아 있다
                $jobs = ShopRankJob::claim($workerId, $limit);
            }
        }

        return response()->json(['data' => [
            'items' => array_map(fn (ShopRankJob $j) => [
                'job_id' => (int) $j->id,
                'keyword' => (string) $j->keyword,
                'pages' => (int) $j->pages,
                // 수집만 시키고 매칭은 서버가 한다 — 규칙이 두 곳으로 갈라지지 않게.
                // count = 몇 위까지 뒤질지. 순위추적은 shopping.track_depth(기본 400위)를 본다
                // (서버 배치와 같은 깊이 — 여기서 얕게 끊으면 순위가 '미노출'로 잘못 나온다).
                'count' => max(80, (int) $j->pages * 80),
                /*
                 * 🔴 조기 중단 힌트 — 대상을 찾으면 그 자리에서 페이지 수집을 멈추라는 뜻이다.
                 * 없으면 3위에 있는 상품도 13페이지를 다 긁는다(= 네이버 차단을 자초한다).
                 * 판정(광고 제외 오가닉 순위)은 여전히 서버가 한다 — 이건 "그만 긁어도 된다"는 신호일 뿐.
                 */
                'match' => [
                    'product_id' => (string) $j->product_id,
                    'mall_name' => (string) $j->mall_name,
                ],
            ], $jobs),
            'lease_seconds' => ShopRankJob::LEASE_SECONDS,
        ]]);
    }

    /**
     * 수집 결과 제출 — 서버가 매칭·순위 계산 후 확정한다.
     * 상품 배열은 **네이버 노출 순서 그대로** 와야 한다(순위가 곧 배열 순서다).
     */
    public function result(Request $request, ShopRankJob $job): JsonResponse
    {
        $data = $request->validate([
            'worker_id' => 'required|string|max:64',
            'products' => 'required|array|max:2000',
            'products.*' => 'array',
            'total' => 'nullable|integer|min:0',
        ]);

        if ($job->status !== 'claimed' || $job->claimed_by !== $data['worker_id']) {
            // 리스가 끊겨 다른 워커가 이미 가져갔다 — 늦게 온 결과로 덮지 않는다
            return response()->json(['ok' => false, 'message' => 'not_owner'], 409);
        }

        $res = $this->ranker->rank(
            array_values((array) $data['products']),
            $job->target(),
            (int) ($data['total'] ?? 0),
        );

        /*
         * 🔴 "한 페이지도 제대로 못 받았다" 만 부족으로 본다.
         *
         * 부분 수집을 그대로 '미노출'로 기록하면 순위 그래프가 거짓이 된다. 하지만 기준을 높게 잡으면
         * 반대 사고가 난다 — 실측(2026-08-03)에서 1페이지가 80 이 아니라 **46개**로 왔고,
         * 목표(1040)의 90%% 를 요구했더니 정상 수집까지 전부 '부족 → 재시도' 가 되어
         * 큐 전체를 반복해 훑다가 네이버에 IP 가 막혔다.
         * → 한 페이지 분량은 받았는데 못 찾았으면, 그 범위에서는 실제로 미노출인 것으로 확정한다.
         */
        $perPage = 40;
        $wanted = max($perPage, (int) $job->pages * $perPage);
        if (! $res['found'] && $res['scanned'] < $perPage) {
            $job->failAttempt('partial:'.$res['scanned'].'/'.$wanted, 60);

            return response()->json(['ok' => true, 'data' => [
                'job_id' => (int) $job->id,
                'partial' => true, 'scanned' => $res['scanned'], 'wanted' => $wanted,
            ]]);
        }

        $job->complete($res);
        $this->slots->applyJobResult($job);

        return response()->json(['ok' => true, 'data' => [
            'job_id' => (int) $job->id,
            'found' => $res['found'],
            'rank' => $res['rank'],
            'ad' => $res['ad'],
        ]]);
    }

    /**
     * 실패 보고(캡차·차단·타임아웃) — 작업은 버리지 않고 백오프 후 다시 큐에 둔다.
     * 그 PC 가 잠깐 못 쓰는 것뿐이고, 다른 워커가 집어갈 수 있다.
     */
    public function fail(Request $request, ShopRankJob $job): JsonResponse
    {
        $data = $request->validate([
            'worker_id' => 'required|string|max:64',
            'error' => 'nullable|string|max:60',
        ]);

        if ($job->status !== 'claimed' || $job->claimed_by !== $data['worker_id']) {
            return response()->json(['ok' => false, 'message' => 'not_owner'], 409);
        }

        $job->failAttempt((string) ($data['error'] ?? 'unknown'));

        return response()->json(['ok' => true, 'data' => ['status' => (string) $job->status]]);
    }
}
