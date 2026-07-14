<?php

namespace App\Domain\Shopping;

use App\Domain\Blog\BlogIndexAnalyzer;
use App\Domain\Keyword\NaverKeywordService;

/**
 * 쇼핑 상품명 SEO 분석 — 검색 리스트 상위(광고 제외) 상품명 기반.
 *   ① 각 제목 키워드 히트·SEO 점수  ② 상위 공통단어  ③ 추천 상품명  ④ 노출 예상 키워드.
 * 제목 채점은 SellerPowerScorer 적합도 축과 동일 언어(키워드 히트·길이 최적화)를 절대점수로 재조립.
 */
class ShoppingTitleSeoAnalyzer
{
    public function __construct(private NaverKeywordService $keywordService) {}

    private function norm(string $s): string
    {
        return preg_replace('/\s+/u', '', mb_strtolower($s));
    }

    /** 상품명 길이 최적화 — 네이버 권장 ~50자, 과도한 키워드 나열 감점(SellerPowerScorer::titleLenScore 동일). */
    private function lenScore(int $len): float
    {
        if ($len === 0) {
            return 20;
        }
        if ($len <= 50) {
            return 100;
        }
        if ($len <= 70) {
            return 80;
        }
        if ($len <= 100) {
            return 55;
        }

        return 35;
    }

    /** 키워드를 공백 단위로 나눠 각 단어의 제목 포함 수(다단어 키워드 대응). */
    private function kwHits(string $title, string $keyword): int
    {
        $t = $this->norm($title);
        $hits = 0;
        foreach (preg_split('/\s+/u', trim($keyword)) as $w) {
            $wn = $this->norm((string) $w);
            if ($wn !== '' && mb_strpos($t, $wn) !== false) {
                $hits++;
            }
        }

        return $hits;
    }

    /**
     * @param  array  $products  [{title, rank, is_ad}]
     * @return array{common_words:array, suggested_titles:array, exposure_keywords:array, products:array}
     */
    public function analyze(string $keyword, array $products): array
    {
        // 광고 제외 상위 상품명(공통단어·추천의 근거)
        $titles = [];
        foreach ($products as $p) {
            if (empty($p['is_ad']) && ! empty($p['title'])) {
                $titles[] = (string) $p['title'];
            }
        }
        $topTitles = array_slice($titles, 0, 40);

        // ② 공통단어 — 상위 제목 명사 빈도(BlogIndexAnalyzer::topWords 재사용)
        $common = (new BlogIndexAnalyzer)->topWords(implode(' ', $topTitles), 25, 2); // [{word, count}]
        $commonWords = array_map(fn ($c) => $c['word'], $common);

        return [
            'common_words' => $common,
            'suggested_titles' => $this->suggestTitles($keyword, $commonWords),
            'exposure_keywords' => $this->exposureKeywords($keyword, $commonWords),
            'products' => array_map(function ($p) use ($keyword, $commonWords) {
                return $this->titleScore((string) ($p['title'] ?? ''), $keyword, $commonWords) + [
                    'title' => $p['title'] ?? '', 'rank' => $p['rank'] ?? null, 'is_ad' => ! empty($p['is_ad']),
                ];
            }, $products),
        ];
    }

    /** 개별 제목 SEO 점수 + 제목에 사용된 키워드(표기용). */
    public function titleScore(string $title, string $keyword, array $commonWords): array
    {
        $t = $this->norm($title);
        $len = mb_strlen($title);

        $kwWords = array_values(array_filter(preg_split('/\s+/u', trim($keyword)), fn ($w) => $w !== ''));
        $kwHit = $this->kwHits($title, $keyword);
        $kwScore = count($kwWords) ? min(100, ($kwHit / count($kwWords)) * 100) : 0;

        $used = [];
        foreach ($commonWords as $w) {
            $wn = $this->norm($w);
            if ($wn !== '' && mb_strpos($t, $wn) !== false) {
                $used[] = $w;
            }
        }
        $denom = max(1, min(10, count($commonWords)));
        $commonScore = min(100, (count($used) / $denom) * 100);

        // 종합: 키워드 적합 40% + 상위 공통단어 반영 30% + 길이 최적화 30%
        $score = (int) round($kwScore * 0.4 + $commonScore * 0.3 + $this->lenScore($len) * 0.3);

        return [
            'score' => $score,
            'len' => $len,
            'kw_hit' => $kwHit,
            'used_keywords' => array_slice($used, 0, 8),
        ];
    }

    /** ④ 노출 예상 키워드 — 연관키워드 중 상위 공통단어를 포함하는 것(검색량 보유). */
    private function exposureKeywords(string $keyword, array $commonWords): array
    {
        $data = $this->keywordService->analyze($keyword);
        $related = is_array($data['related'] ?? null) ? $data['related'] : [];
        $set = array_values(array_filter(array_map(fn ($w) => $this->norm($w), array_slice($commonWords, 0, 15))));

        $out = [];
        foreach ($related as $r) {
            $rn = $this->norm((string) ($r['keyword'] ?? ''));
            if ($rn === '') {
                continue;
            }
            foreach ($set as $cw) {
                if ($cw !== '' && mb_strpos($rn, $cw) !== false) {
                    $out[] = ['keyword' => $r['keyword'], 'volume' => $r['monthly_total'] ?? null];
                    break;
                }
            }
            if (count($out) >= 15) {
                break;
            }
        }

        return $out;
    }

    /** ③ 추천 상품명 — 키워드 + 상위 공통단어 조합(중복 없이). */
    private function suggestTitles(string $keyword, array $commonWords): array
    {
        // 키워드에 이미 든 단어는 제외
        $kwNorm = $this->norm($keyword);
        $words = [];
        foreach ($commonWords as $w) {
            if (mb_strpos($kwNorm, $this->norm($w)) === false) {
                $words[] = $w;
            }
            if (count($words) >= 12) {
                break;
            }
        }
        $out = [];
        foreach (array_chunk($words, 4) as $ch) {
            $title = trim($keyword.' '.implode(' ', $ch));
            if ($title !== '') {
                $out[] = $title;
            }
            if (count($out) >= 3) {
                break;
            }
        }

        return $out;
    }
}
