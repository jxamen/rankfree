<?php

namespace App\Domain\Keyword;

use App\Domain\Blog\BlogIndexAnalyzer;
use App\Domain\SearchAdWeb\SearchAdWebClient;
use Illuminate\Support\Facades\Cache;

/**
 * 키워드 분석 리포트 조립 — 검색량·상세(성별/연령/트렌드)·포화·인기글·요일별·자동완성.
 * KeywordAnalysisController(콘솔·공유)와 허브 발행(hub:publish)이 공용으로 사용.
 * 상세는 6h·요일별은 24h 캐시. (KeywordAnalysisController::buildAnalysis 에서 추출)
 */
class KeywordReportBuilder
{
    public function __construct(
        private NaverKeywordService $light,
        private SearchAdWebClient $web,
        private NaverContentVolumeService $content,
        private NaverDataLabService $datalab,
        private NaverAutocompleteService $ac,
        private BlogIndexAnalyzer $blog,
    ) {}

    /** @return array{vm:?array,detail:?array,saturation:?array,popular:array,weekday:?array,autocomplete:array} */
    public function build(string $keyword): array
    {
        $autocomplete = $this->ac->suggest($keyword);
        $base = $this->light->analyze($keyword);

        // 상세(성별·연령·트렌드)는 성공만 6시간 캐시 — 오류는 캐시하지 않음
        $cacheKey = 'kw:detail:'.md5(mb_strtoupper(str_replace(' ', '', $keyword)));
        $detail = Cache::get($cacheKey);
        if ($detail === null) {
            $d = $this->web->keywordDetail($keyword);
            $detail = isset($d['error']) ? null : $d;
            if ($detail !== null) {
                Cache::put($cacheKey, $detail, now()->addHours(6));
            }
        }

        // 쇼핑 상품 수(구매 의도) — 상업성 판정 보강. 볼륨 있을 때만 조회.
        $shopTotal = $base !== null ? $this->content->shopTotal($keyword) : null;
        $vm = KeywordAnalysisPresenter::build($keyword, $base, $detail, $shopTotal);

        // 콘텐츠 포화 지수 — 블로그·카페 통합 발행량(누적) ÷ 월검색량 (openapi 2요청, 가벼움)
        $saturation = null;
        if (($vm['has_volume'] ?? false) && ($vm['total'] ?? 0) > 0) {
            $saturation = KeywordAnalysisPresenter::saturation($this->content->counts($keyword), (int) $vm['total']);
        }

        // 인기글 TOP + 요일별 검색 비율(세션 불필요)
        $popular = [];
        $weekday = null;
        if (($vm['has_volume'] ?? false)) {
            $popular = $this->content->popular($keyword);
            $weekday = $this->datalab->weekdayRatio($keyword);

            // 인기글 블로그 항목에 경량 블로그 등급(프로필 기반) 부여
            $blogUrls = array_values(array_filter(array_map(
                fn ($p) => ($p['source'] ?? '') === '블로그' ? $p['link'] : null,
                $popular,
            )));
            if ($blogUrls) {
                $grades = $this->blog->quickGrades($blogUrls);
                foreach ($popular as $i => $p) {
                    if (($p['source'] ?? '') === '블로그') {
                        $bid = $this->blog->blogIdFrom($p['link']);
                        $popular[$i]['blog_id'] = $bid;
                        $popular[$i]['grade'] = $grades[$bid] ?? null;
                    }
                }
            }
        }

        return [
            'vm' => $vm,
            'detail' => $detail,
            'saturation' => $saturation,
            'popular' => $popular,
            'weekday' => $weekday,
            'autocomplete' => $autocomplete,
        ];
    }
}
