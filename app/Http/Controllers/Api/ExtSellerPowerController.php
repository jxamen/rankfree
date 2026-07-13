<?php

namespace App\Http\Controllers\Api;

use App\Domain\Seller\SellerPowerScorer;
use App\Http\Controllers\Controller;
use App\Models\SellerPowerAnalysis;
use App\Models\SellerTalkContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * 크롬 확장 — 셀러력(쇼핑 상품 SEO·지수 경쟁 비교) 수집·계산·저장 API.
 * 확장이 내 상품 + 검색 상위 경쟁 상품 raw 데이터를 보내면, 서버가 5축 점수를 계산해
 * 저장하고 결과를 반환한다(확장 패널이 그 결과를 즉시 렌더). 설계: .claude/15_SELLER_POWER.md
 */
class ExtSellerPowerController extends Controller
{
    public function store(Request $request, SellerPowerScorer $scorer): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:120'],
            'product_url' => ['required', 'string', 'max:500'],
            'mode' => ['nullable', 'string', 'in:detail,search'],
            'terms' => ['nullable', 'array'],
            'my' => ['required', 'array'],
            'competitors' => ['required', 'array', 'max:20'],
        ]);

        if (strlen((string) json_encode($data)) > 3_000_000) {
            return response()->json(['ok' => false, 'message' => '수집 데이터가 너무 큽니다.'], 422);
        }

        // search: 검색 리스트 아이템만으로 채점 / detail: 상품 상세(__PRELOADED_STATE__)
        $result = ($data['mode'] ?? 'detail') === 'search'
            ? $scorer->scoreSearch($data)
            : $scorer->score($data);

        $analysis = SellerPowerAnalysis::updateOrCreate(
            ['user_id' => $request->user()->id, 'product_url' => $data['product_url'], 'keyword' => $data['keyword']],
            [
                'product_name' => mb_substr((string) $result['product_name'], 0, 300),
                'store_id' => $this->storeIdFrom($data['product_url']),
                'score' => $result['score'],
                'grade' => $result['grade'],
                'market_percentile' => $result['market_percentile'],
                'rank_in_top' => $result['rank_in_top'],
                'competitor_count' => $result['competitor_count'],
                'snapshot' => $result,
            ],
        );

        return response()->json([
            'ok' => true,
            'id' => $analysis->id,
            'share_token' => $analysis->shareToken(),
            'result' => $result,
        ]);
    }

    /**
     * 경쟁 상품 목록 — 네이버 쇼핑 openapi(shop.json)로 상위 상품 URL 확보.
     * 검색 API(search.shopping)는 봇 차단(418)이라, 서버가 공식 API로 대신 검색한다.
     * 스마트스토어/브랜드스토어 상품만(확장이 상세 __PRELOADED_STATE__를 읽을 수 있는 것).
     */
    public function competitors(Request $request): JsonResponse
    {
        $keyword = trim((string) $request->query('keyword', ''));
        if ($keyword === '') {
            return response()->json(['ok' => false, 'products' => []]);
        }
        $keys = (array) config('rankfree.shopping.api_keys');
        foreach ($keys as $k) {
            $res = Http::withHeaders([
                'X-Naver-Client-Id' => $k['id'],
                'X-Naver-Client-Secret' => $k['secret'],
            ])->get('https://openapi.naver.com/v1/search/shop.json', [
                'query' => $keyword, 'display' => 40, 'sort' => 'sim',
            ]);
            $items = $res->json('items');
            if (! is_array($items)) {
                continue; // 키 소진 → 다음 키
            }
            $out = [];
            $seen = [];
            foreach ($items as $it) {
                $link = (string) ($it['link'] ?? '');
                if (! preg_match('#(smartstore|brand)\.naver\.com#', $link)) {
                    continue; // 스마트스토어/브랜드만
                }
                if (isset($seen[$link])) {
                    continue;
                }
                $seen[$link] = true;
                $out[] = [
                    'url' => $link,
                    'rank' => count($out) + 1,
                    'mall' => (string) ($it['mallName'] ?? ''),
                    'title' => strip_tags((string) ($it['title'] ?? '')),
                    'price' => (int) ($it['lprice'] ?? 0),
                ];
                if (count($out) >= 12) {
                    break;
                }
            }

            return response()->json(['ok' => true, 'products' => $out]);
        }

        return response()->json(['ok' => true, 'products' => []]);
    }

    /**
     * 톡톡/스토어 연락 식별자 수집 — 상품 리스트 수집 시 확보한 판매자 핸들을
     * 키워드·몰이름·순위·톡톡아이디·수집일로 저장(마케팅 리드). 조회는 슈퍼어드민만.
     */
    public function harvestTalk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:120'],
            'contacts' => ['required', 'array', 'max:300'],
            'contacts.*.mall_name' => ['nullable', 'string', 'max:200'],
            'contacts.*.rank' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'contacts.*.talk_id' => ['required', 'string', 'max:150'],
        ]);

        $now = now();
        $saved = 0;
        foreach ($data['contacts'] as $c) {
            $tid = trim((string) ($c['talk_id'] ?? ''));
            if ($tid === '') {
                continue;
            }
            // talk_id 전역 유니크 — 같은 업체가 여러 키워드에 걸쳐도 1건만(최신 키워드·순위로 갱신)
            SellerTalkContact::updateOrCreate(
                ['talk_id' => $tid],
                [
                    'keyword' => $data['keyword'],
                    'mall_name' => $c['mall_name'] ?? null,
                    'rank' => (int) ($c['rank'] ?? 0),
                    'collected_by' => $request->user()->id,
                    'collected_at' => $now,
                ],
            );
            $saved++;
        }

        return response()->json(['ok' => true, 'saved' => $saved]);
    }

    public function index(Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->query('limit', 20)));

        return response()->json([
            'data' => $request->user()->sellerPowerAnalyses()->latest('updated_at')->limit($limit)->get([
                'id', 'keyword', 'product_name', 'store_id', 'score', 'grade',
                'market_percentile', 'rank_in_top', 'competitor_count', 'updated_at',
            ]),
        ]);
    }

    public function show(Request $request, SellerPowerAnalysis $analysis): JsonResponse
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        $analysis->shareToken(); // 공유 토큰 보장(내역에서 열어도 공유 가능)

        return response()->json(['data' => $analysis]);
    }

    /** smartstore.naver.com/{store}/products/{no} → store */
    private function storeIdFrom(string $url): ?string
    {
        if (preg_match('#(?:smartstore|brand)\.naver\.com/([^/]+)/#', $url, $m)) {
            return $m[1];
        }

        return null;
    }
}
