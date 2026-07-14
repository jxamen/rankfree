<?php

namespace App\Http\Controllers\Api;

use App\Domain\Keyword\KeywordAnalysisPresenter;
use App\Domain\Keyword\NaverDataLabService;
use App\Domain\Keyword\NaverKeywordService;
use App\Domain\Keyword\NaverSerpService;
use App\Domain\SearchAdWeb\SearchAdWebClient;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * 키워드 분석 API — 경량과 상세를 별도 scope 로 분리 제공(상품·한도 개별 관리).
 * 경량(scope: keyword): 월간 검색량·경쟁강도·연관 키워드 — 공식 HMAC API(keywordstool)
 * 상세(scope: keyword_detail): 경량 + 성별·연령 분포 + 최근 12개월 트렌드 — 검색광고 웹 세션
 */
class KeywordController extends Controller
{
    /**
     * API 키(v1) 호출에만 월간 기능 한도를 적용. 확장(ext 토큰)에서 시장분석에 딸려오는
     * 내부 키워드 조회는 계량하지 않아 이중 차감을 피한다.
     */
    private function overFeatureLimit(Request $request): ?JsonResponse
    {
        if ($request->attributes->get('api_key') === null) {
            return null; // API 키 호출이 아니면 미계량
        }
        $user = $request->user();
        if (! $user->tryConsumeFeature('keyword_analysis')) {
            return response()->json([
                'data' => null,
                'limit_exceeded' => true,
                'message' => '이번 달 키워드 분석 호출 한도('.$user->featureLimit('keyword_analysis').'회)를 초과했습니다.',
            ], 429);
        }

        return null;
    }

    /** 경량 분석. */
    public function show(Request $request, NaverKeywordService $service): JsonResponse
    {
        $keyword = trim((string) $request->query('keyword', ''));
        if ($keyword === '') {
            return response()->json(['data' => null, 'message' => 'keyword 파라미터가 필요합니다.'], 422);
        }
        if ($over = $this->overFeatureLimit($request)) {
            return $over;
        }

        $data = $service->analyze($keyword);

        return response()->json([
            'data' => $data,
            'message' => $data === null ? '키워드 검색량 데이터를 조회하지 못했습니다.' : null,
        ]);
    }

    /**
     * '함께 많이 찾는'(SERP qra 모듈) — 확장이 DOM scrape 대신 서버에서 받아 사용.
     * 각 항목 badge(place+·새로오픈 등) 포함. 서버가 SERP를 크롤링(24h 캐시)하므로 확장 lazy 로드 문제 없음.
     */
    public function together(Request $request, NaverSerpService $serp): JsonResponse
    {
        $keyword = trim((string) $request->query('keyword', ''));
        if ($keyword === '') {
            return response()->json(['data' => []], 422);
        }

        $data = $serp->sections($keyword);

        return response()->json(['data' => $data['related'] ?? []]);
    }

    /** 상세 분석 — 경량 지표 + 성별/연령 분포 + 월별 트렌드. */
    public function detail(Request $request, NaverKeywordService $light, SearchAdWebClient $web, NaverDataLabService $datalab): JsonResponse
    {
        $keyword = trim((string) $request->query('keyword', ''));
        if ($keyword === '') {
            return response()->json(['data' => null, 'message' => 'keyword 파라미터가 필요합니다.'], 422);
        }
        if ($over = $this->overFeatureLimit($request)) {
            return $over;
        }

        $base = $light->analyze($keyword);

        // 상세(성별·연령·트렌드)는 성공 응답만 6시간 캐시 — 오류는 캐시하지 않는다
        $cacheKey = 'searchadweb:kwdetail:'.md5(mb_strtoupper(str_replace(' ', '', $keyword)));
        $detail = Cache::get($cacheKey);
        if ($detail === null) {
            $detail = $web->keywordDetail($keyword);
            if (! isset($detail['error'])) {
                Cache::put($cacheKey, $detail, now()->addHours(6));
            }
        }

        if (isset($detail['error'])) {
            // 세션·통신 문제는 서버측 일시 장애로 알린다 (데이터 없음과 구분)
            if (in_array($detail['error'], ['no_session', 'unauthorized'], true) || str_starts_with($detail['error'], 'http_')) {
                return response()->json(['data' => null, 'message' => '상세 분석 소스에 일시적으로 연결할 수 없습니다. 잠시 후 다시 시도하세요.'], 503);
            }
            $detail = null; // 'empty' 등 — 해당 키워드 데이터 없음
        }

        if ($base === null && $detail === null) {
            return response()->json(['data' => null, 'message' => '키워드 데이터를 조회하지 못했습니다.']);
        }

        // 검색량 등급(S~F) — 콘솔 키워드 분석과 동일 지표. 쇼핑성 지표는 키워드 분석에서 제외.
        $grade = $base !== null ? KeywordAnalysisPresenter::grade((int) ($base['monthly_total'] ?? 0)) : null;
        // 요일별 검색 비율(월~일, DataLab) — 상세 섹션과 별개 소스
        $weekday = $base !== null ? $datalab->weekdayRatio($keyword) : null;

        // 데이터 기반 자동 인사이트(성별·연령·계절성 자연어 요약) — 콘솔 키워드 분석과 동일 로직 재사용
        $insights = $detail === null ? null : (KeywordAnalysisPresenter::detailModel($detail)['insights'] ?? null);

        $data = array_merge($base ?? ['keyword' => $keyword], [
            'grade' => $grade,
            'weekday' => $weekday,
            'detail' => $detail === null ? null : [
                'gender' => $detail['gender'],
                'age' => $detail['age'],
                'monthly' => $detail['monthly'],
                'buckets' => $detail['buckets'],
                'insights' => $insights,
            ],
        ]);

        // 조회 성공 시 KeywordSearch 레코드로 저장 → 공유 토큰(/k/{token}) 발급
        $shareToken = null;
        if ($request->user()) {
            $record = \App\Models\KeywordSearch::updateOrCreate(
                ['user_id' => $request->user()->id, 'keyword' => $keyword],
                [
                    'monthly_total' => (int) ($data['monthly_total'] ?? 0),
                    'monthly_pc' => (int) ($data['monthly_pc'] ?? 0),
                    'monthly_mobile' => (int) ($data['monthly_mobile'] ?? 0),
                    'comp_idx' => $data['comp_idx'] ?? null,
                    'grade' => $grade,
                    'snapshot' => $data,
                ]
            );
            $shareToken = $record->shareToken();
        }

        return response()->json([
            'data' => $data,
            'share_token' => $shareToken,
            'message' => $detail === null ? '상세(성별·연령·트렌드) 데이터가 없습니다.' : null,
        ]);
    }
}
