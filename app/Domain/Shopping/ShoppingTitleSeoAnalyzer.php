<?php

namespace App\Domain\Shopping;

use App\Domain\Blog\BlogIndexAnalyzer;
use App\Domain\Keyword\NaverKeywordService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

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
        // 공통단어 기준 제목 — 키워드 기준으로 서버가 고정(shop.json 상위).
        // 호출 경로(네이버 페이지 배지·확장 패널·웹 시장분석)마다 보내는 상품이 달라도 같은 제목이 같은 점수가 되도록 한다.
        $topTitles = $this->baseTitles($keyword);
        if (! $topTitles) {
            // 수집 실패 시에만 입력 상품(광고 제외 상위 40)으로 폴백
            $titles = [];
            foreach ($products as $p) {
                if (empty($p['is_ad']) && ! empty($p['title'])) {
                    $titles[] = (string) $p['title'];
                }
            }
            $topTitles = array_slice($titles, 0, 40);
        }

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

    /**
     * 공통단어 산출 기준 제목 — 키워드의 네이버 쇼핑 상위 40(openapi shop.json, sort=sim). 성공만 6시간 캐시.
     * 점수 기준을 키워드에 고정해, 호출 경로(배지·패널·웹)별 입력 상품 차이로 같은 상품 점수가 갈리지 않게 한다.
     * 실패 시 빈 배열 → 호출부에서 입력 상품으로 폴백.
     */
    private function baseTitles(string $keyword): array
    {
        $kw = trim($keyword);
        if ($kw === '') {
            return [];
        }
        $ck = 'shop_seo_base:'.md5(mb_strtolower($kw));
        $hit = Cache::get($ck);
        if (is_array($hit)) {
            return $hit;
        }

        $titles = [];
        foreach ((array) (config('rankfree.shopping')['api_keys'] ?? []) as $key) {
            try {
                $resp = Http::withHeaders([
                    'X-Naver-Client-Id' => (string) ($key['id'] ?? ''),
                    'X-Naver-Client-Secret' => (string) ($key['secret'] ?? ''),
                ])->timeout(10)->get('https://openapi.naver.com/v1/search/shop.json', [
                    'query' => $kw, 'display' => 40, 'start' => 1, 'sort' => 'sim',
                ]);
            } catch (Throwable $e) {
                continue;
            }
            if ($resp->status() === 429) {
                continue; // 한도 초과 — 다음 키로
            }
            if ($resp->status() !== 200) {
                break;
            }
            $titles = array_values(array_filter(array_map(
                fn ($it) => trim(strip_tags((string) ($it['title'] ?? ''))), // shop.json title 은 <b> 강조 포함
                (array) ($resp->json()['items'] ?? [])
            )));
            break;
        }
        // 실패(빈 결과)도 10분 네거티브 캐시 — 쿼터 소진(429) 중 공유 페이지가 매 요청 키 수만큼
        // 라이브 재시도(최대 키×10초)로 느려지던 문제 방지. 성공은 6시간.
        Cache::put($ck, $titles, $titles ? 6 * 3600 : 600);

        return $titles;
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

        // 표기용 — 공통단어 매칭이 없으면 제목에 실제 든 검색 키워드 단어라도 보여준다(점수엔 미반영)
        $shown = $used;
        if (! $shown) {
            foreach ($kwWords as $w) {
                $wn = $this->norm((string) $w);
                if ($wn !== '' && mb_strpos($t, $wn) !== false) {
                    $shown[] = (string) $w;
                }
            }
        }

        return [
            'score' => $score,
            'len' => $len,
            'kw_hit' => $kwHit,
            'used_keywords' => array_slice($shown, 0, 8),
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
