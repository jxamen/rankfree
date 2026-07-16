<?php

namespace App\Http\Controllers;

use App\Domain\Access\MenuAccess;
use App\Domain\Keyword\KeywordAnalysisPresenter;
use App\Domain\Keyword\KeywordReportBuilder;
use App\Domain\Keyword\NaverAutocompleteService;
use App\Domain\Keyword\NaverKeywordService;
use App\Domain\Keyword\NaverSerpService;
use App\Domain\Seo\RelatedDocsService;
use App\Models\KeywordSearch;
use App\Models\Menu;
use App\Models\User;
use Illuminate\Http\Request;

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

    public function index(Request $request, KeywordReportBuilder $builder)
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
                $shareUrl = $record->shareUrl();
                $fromCache = true;
            } else {
                // 신규 검색·재분석 — 이번 달 이용 횟수 계량(초과 시 차단). "재검색만 카운팅".
                if (! $this->consumeKeywordUsage($user)) {
                    return redirect()->route('console.dashboard')
                        ->with('status', "'키워드 분석' 이번 달 이용 횟수를 모두 사용했습니다. 요금제를 업그레이드하면 더 이용할 수 있습니다.");
                }
                $result = $builder->build($keyword);

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
                    $shareUrl = $record->shareUrl();
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
    public function shared(string $slug, KeywordReportBuilder $builder)
    {
        $record = KeywordSearch::findByShareKey($slug);
        abort_if(! $record, 404);
        $result = $builder->build($record->keyword);
        abort_if($result['vm'] === null || ! ($result['vm']['has_data'] ?? false), 404);
        // 관련 문서 추천 — 연관 키워드(vm.related)도 정확 일치 후보로 넘긴다
        $result['related'] = app(RelatedDocsService::class)
            ->sectionsFor($record, array_column((array) ($result['vm']['related'] ?? []), 'keyword'));
        // 브레드크럼(카테고리)·기준일(AEO/GEO) 렌더용
        $result['record'] = $record->loadMissing('category');
        // AI 인사이트 — 발행/갱신 시 저장된 스냅샷분만 표시(열람 시 LLM 호출 없음)
        $result['aiInsight'] = is_array($record->snapshot) ? ($record->snapshot['ai_insight'] ?? null) : null;

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

    /** 키워드 검색 내역 삭제(본인 것만). */
    public function destroy(Request $request, KeywordSearch $search)
    {
        abort_unless($search->user_id === $request->user()->id, 403);
        $search->delete();

        return back()->with('status', '검색 기록을 삭제했습니다.');
    }
}
