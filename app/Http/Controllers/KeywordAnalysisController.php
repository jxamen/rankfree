<?php

namespace App\Http\Controllers;

use App\Domain\Access\MenuAccess;
use App\Domain\Blog\BlogIndexAnalyzer;
use App\Domain\Keyword\KeywordAnalysisPresenter;
use App\Domain\Keyword\NaverAutocompleteService;
use App\Domain\Keyword\NaverContentVolumeService;
use App\Domain\Keyword\NaverDataLabService;
use App\Domain\Keyword\NaverKeywordService;
use App\Domain\Keyword\NaverSerpService;
use App\Domain\SearchAdWeb\SearchAdWebClient;
use App\Models\KeywordSearch;
use App\Models\Menu;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * 마케팅 키워드 분석 — 콘솔 페이지(console.keyword) + 공개 공유 리포트(/k/{token}).
 * 경량(검색량·경쟁강도·연관키워드) + 상세(성별·연령·월별 트렌드)를 합쳐 대시보드로 렌더.
 * PC/모바일 섹션배치는 Playwright 수집이 무거워 비동기(sections)로 분리 로드한다.
 */
class KeywordAnalysisController extends Controller
{
    /** 통합검색 PC/모바일 섹션 배치 순서 — 비동기(AJAX) 로드. Playwright 수집·24h 캐시. */
    public function sections(Request $request, NaverSerpService $serp)
    {
        $keyword = trim((string) $request->query('keyword', ''));
        if ($keyword === '') {
            return response()->json(['ok' => false]);
        }
        $data = $serp->sections($keyword);

        return response()->json($data ? ['ok' => true] + $data : ['ok' => false]);
    }

    /** 키워드 추천 — 시드 키워드의 연관·자동완성을 "기회 점수"로 랭킹(황금 키워드 발굴). 검색광고 1콜 + 자동완성. */
    public function recommend(Request $request, NaverKeywordService $light, NaverAutocompleteService $ac)
    {
        $keyword = trim((string) $request->query('keyword', ''));
        $seed = null;
        $recommendations = [];
        if ($keyword !== '') {
            $base = $light->analyze($keyword);
            if ($base !== null) {
                $total = (int) ($base['monthly_total'] ?? 0);
                $seed = [
                    'keyword' => $keyword,
                    'total' => $total,
                    'comp_idx' => isset($base['comp_idx']) ? (string) $base['comp_idx'] : null,
                    'grade' => KeywordAnalysisPresenter::grade($total),
                ];
                $recommendations = KeywordAnalysisPresenter::recommend($base + ['keyword' => $keyword], $ac->suggest($keyword, 15));
            }
        }

        return view('console.keyword-recommend', [
            'keyword' => $keyword,
            'seed' => $seed,
            'recommendations' => $recommendations,
            'history' => KeywordSearch::where('user_id', $request->user()->id)->latest('updated_at')->limit(12)->get(),
        ]);
    }

    public function index(Request $request, NaverKeywordService $light, SearchAdWebClient $web, NaverContentVolumeService $content, NaverDataLabService $datalab, NaverAutocompleteService $ac, BlogIndexAnalyzer $blog)
    {
        $user = $request->user();
        $keyword = trim((string) $request->query('keyword', ''));
        // 캐싱 정책: 리스트/링크 열람(view=1)은 항상 캐시(저장본), 검색 폼으로 다시 검색하면 재수집
        $view = $request->boolean('view');

        $result = ['vm' => null, 'saturation' => null, 'popular' => [], 'weekday' => null, 'autocomplete' => []];
        $shareUrl = null;
        $fromCache = false;

        if ($keyword !== '') {
            $record = KeywordSearch::where('user_id', $user->id)->where('keyword', $keyword)->first();
            $snap = $record ? (array) $record->snapshot : [];

            if ($view && $record && count($snap)) {
                if (! empty($snap['vm'])) {
                    // 신규 포맷 스냅샷 — 그대로 렌더 (재수집·과금 없음)
                    $result = $snap + $result;
                } else {
                    // 구버전(원시 base+detail 포맷, 대량 분석 등) 스냅샷 — 저장 데이터만으로
                    // vm 을 구성해 재수집·과금 없이 표시한다. (발행량·인기글 등 미저장 항목은 생략)
                    $base = array_intersect_key($snap, array_flip(['keyword', 'monthly_pc', 'monthly_mobile', 'monthly_total', 'comp_idx', 'related']));
                    $detail = (array) ($snap['detail'] ?? []);
                    $result['vm'] = KeywordAnalysisPresenter::build(
                        $keyword,
                        $base ?: null,
                        $detail ?: null,
                        isset($snap['shop_total']) ? (int) $snap['shop_total'] : null,
                    );
                }
                $shareUrl = route('keyword.shared', $record->shareToken());
                $fromCache = true;
            } else {
                // 신규 검색·재분석 — 이번 달 이용 횟수 계량(초과 시 차단). "재검색만 카운팅".
                if (! $this->consumeKeywordUsage($user)) {
                    return redirect()->route('console.dashboard')
                        ->with('status', "'키워드 분석' 이번 달 이용 횟수를 모두 사용했습니다. 요금제를 업그레이드하면 더 이용할 수 있습니다.");
                }
                $result = $this->buildAnalysis($keyword, $light, $web, $content, $datalab, $ac, $blog);

                // 검색 내역 + 스냅샷 저장(같은 키워드는 갱신) + 공개 공유 링크 — 볼륨 있을 때만
                if ($result['vm'] && ($result['vm']['has_volume'] ?? false)) {
                    $record = KeywordSearch::updateOrCreate(
                        ['user_id' => $user->id, 'keyword' => $keyword],
                        [
                            'monthly_total' => $result['vm']['total'],
                            'monthly_pc' => $result['vm']['pc'],
                            'monthly_mobile' => $result['vm']['mobile'],
                            'comp_idx' => $result['vm']['comp_idx'],
                            'grade' => $result['vm']['grade'],
                            'snapshot' => $result,
                        ],
                    );
                    $shareUrl = route('keyword.shared', $record->shareToken());
                }
            }
        }

        return view('console.keyword.index', $result + [
            'keyword' => $keyword,
            'shareUrl' => $shareUrl,
            'fromCache' => $fromCache,
            'history' => KeywordSearch::where('user_id', $user->id)->latest('updated_at')->limit(12)->get(),
            'usedSlots' => $user->rankSlotsUsedTotal(),
            'maxSlots' => $user->rankSlotLimit(),
        ]);
    }

    /** 공개 공유 리포트 — 공유 토큰으로 비로그인 열람(실시간 재조회, 캐시 활용). */
    public function shared(string $token, NaverKeywordService $light, SearchAdWebClient $web, NaverContentVolumeService $content, NaverDataLabService $datalab, NaverAutocompleteService $ac, BlogIndexAnalyzer $blog)
    {
        $record = KeywordSearch::where('share_token', $token)->firstOrFail();
        $result = $this->buildAnalysis($record->keyword, $light, $web, $content, $datalab, $ac, $blog);
        abort_if($result['vm'] === null || ! ($result['vm']['has_data'] ?? false), 404);

        return view('keyword.share', $result);
    }

    /** 키워드 분석 이용 횟수 계량 — 재분석(신규 검색)에만 1회 소진. 한도 초과 시 false. */
    private function consumeKeywordUsage(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        $menu = Menu::where('route', 'console.keyword')->first();
        if (! $menu) {
            return true;
        }
        $limit = MenuAccess::menuLimitFor($user, $menu);

        return $limit < 0 ? true : $user->tryConsumeUsage('menu:'.$menu->id, $limit);
    }

    /**
     * 키워드 분석 실행 — 검색량·상세(성별/연령/트렌드)·포화·인기글·요일별·자동완성.
     * 콘솔(index)과 공개 공유(shared) 양쪽에서 재사용. 상세는 6h·요일별은 24h 캐시.
     *
     * @return array{vm:?array,saturation:?array,popular:array,weekday:?array,autocomplete:array}
     */
    private function buildAnalysis(string $keyword, NaverKeywordService $light, SearchAdWebClient $web, NaverContentVolumeService $content, NaverDataLabService $datalab, NaverAutocompleteService $ac, BlogIndexAnalyzer $blog): array
    {
        $autocomplete = $ac->suggest($keyword);
        $base = $light->analyze($keyword);

        // 상세(성별·연령·트렌드)는 성공만 6시간 캐시 — 오류는 캐시하지 않음
        $cacheKey = 'kw:detail:'.md5(mb_strtoupper(str_replace(' ', '', $keyword)));
        $detail = Cache::get($cacheKey);
        if ($detail === null) {
            $d = $web->keywordDetail($keyword);
            $detail = isset($d['error']) ? null : $d;
            if ($detail !== null) {
                Cache::put($cacheKey, $detail, now()->addHours(6));
            }
        }

        // 쇼핑 상품 수(구매 의도) — 상업성 판정 보강. 볼륨 있을 때만 조회.
        $shopTotal = $base !== null ? $content->shopTotal($keyword) : null;
        $vm = KeywordAnalysisPresenter::build($keyword, $base, $detail, $shopTotal);

        // 콘텐츠 포화 지수 — 블로그·카페 통합 발행량(누적) ÷ 월검색량 (openapi 2요청, 가벼움)
        $saturation = null;
        if (($vm['has_volume'] ?? false) && ($vm['total'] ?? 0) > 0) {
            $saturation = KeywordAnalysisPresenter::saturation($content->counts($keyword), (int) $vm['total']);
        }

        // 인기글 TOP + 요일별 검색 비율(세션 불필요)
        $popular = [];
        $weekday = null;
        if (($vm['has_volume'] ?? false)) {
            $popular = $content->popular($keyword);
            $weekday = $datalab->weekdayRatio($keyword);

            // 인기글 블로그 항목에 경량 블로그 등급(프로필 기반) 부여
            $blogUrls = array_values(array_filter(array_map(
                fn ($p) => ($p['source'] ?? '') === '블로그' ? $p['link'] : null,
                $popular,
            )));
            if ($blogUrls) {
                $grades = $blog->quickGrades($blogUrls);
                foreach ($popular as $i => $p) {
                    if (($p['source'] ?? '') === '블로그') {
                        $bid = $blog->blogIdFrom($p['link']);
                        $popular[$i]['blog_id'] = $bid;
                        $popular[$i]['grade'] = $grades[$bid] ?? null;
                    }
                }
            }
        }

        return [
            'vm' => $vm,
            'saturation' => $saturation,
            'popular' => $popular,
            'weekday' => $weekday,
            'autocomplete' => $autocomplete,
        ];
    }
}
