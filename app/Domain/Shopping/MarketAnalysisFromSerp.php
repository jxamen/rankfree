<?php

namespace App\Domain\Shopping;

use App\Models\MarketAnalysis;
use App\Models\User;

/**
 * 확장 벌크 수집 SERP → 쇼핑 시장 분석(25/22) — C1 패널(computeMarket)과 동일 산식의 서버 포트.
 * 확장이 상품별 구매건수(purchase6m)·카탈로그 보강 매출(revenue6m)을 보내면 판매량·매출·top10 점유까지
 * 실측 기반으로 계산해 **수집 유저 소유** MarketAnalysis 를 만든다(키워드당 1건 갱신).
 * 허브 발행(KeywordHubPublisher::latestMarketSource)이 이 문서를 복제해 공개한다 — "쇼핑 시장 분석은 확장 플로만".
 */
class MarketAnalysisFromSerp
{
    private const GRADE_ORDER = ['프리미엄', '빅파워', '파워', '브랜드스토어', '스마트스토어', '일반', '가격비교', '해외직구', '기타'];

    /**
     * @param  array<int, array<string, mixed>>  $products  확장 rfcollect 상품(카탈로그 보강 포함)
     * @param  list<string>  $relatedTags
     */
    public function save(User $user, string $keyword, array $products, int $totalCount, array $relatedTags = []): ?MarketAnalysis
    {
        // C1 기본값과 동일하게 광고 제외(오가닉) 기준으로 계산한다
        $items = array_values(array_filter($products, fn ($p) => empty($p['isAd'])));
        if ($items === []) {
            return null;
        }

        $rev = fn (array $p) => $p['revenue6m'] !== null && $p['revenue6m'] !== ''
            ? (int) $p['revenue6m']
            : (int) ($p['purchase6m'] ?? 0) * (int) ($p['price'] ?? 0);

        $withSales = array_values(array_filter($items, fn ($p) => (int) ($p['price'] ?? 0) > 0 || $rev($p) > 0));
        $sales6m = array_sum(array_map(fn ($p) => (int) ($p['purchase6m'] ?? 0), $withSales));
        $revenue6m = array_sum(array_map($rev, $withSales));
        $prices = array_values(array_filter(array_map(fn ($p) => (int) ($p['price'] ?? 0), $withSales), fn ($v) => $v > 0));
        sort($prices);
        $avg = $prices !== [] ? (int) round(array_sum($prices) / count($prices)) : 0;
        $median = $prices !== [] ? (int) $prices[(int) floor((count($prices) - 1) / 2)] : 0;

        $byRevenue = $withSales;
        usort($byRevenue, fn ($a, $b) => $rev($b) <=> $rev($a));
        $top10Revenue = array_sum(array_map($rev, array_slice($byRevenue, 0, 10)));

        $gradeMap = [];
        $catMap = [];
        foreach ($items as $p) {
            $g = (string) ($p['mallGrade'] ?? '') ?: '기타';
            $gradeMap[$g] = ($gradeMap[$g] ?? 0) + 1;
            if (($c = (string) ($p['category'] ?? '')) !== '') {
                $catMap[$c] = ($catMap[$c] ?? 0) + 1;
            }
        }
        $mallGrades = array_values(array_map(
            fn ($g) => [$g, $gradeMap[$g]],
            array_values(array_filter(self::GRADE_ORDER, fn ($g) => isset($gradeMap[$g]))),
        ));
        arsort($catMap);
        $topCategories = array_map(fn ($k, $v) => [$k, $v], array_keys(array_slice($catMap, 0, 5, true)), array_slice($catMap, 0, 5));

        $snapshot = [
            'related_tags' => array_slice(array_values($relatedTags), 0, 15),
            'keyword_data' => null,               // 검색량 상세는 발행(latestMarketSource 복제) 쪽 데이터로 보강됨
            'count' => count($products),
            'top_product_category' => $byRevenue !== [] ? (string) ($byRevenue[0]['category'] ?? '') : '',
            'mall_grades' => $mallGrades,
            'top_categories' => $topCategories,
            'top_products' => array_map(fn ($p) => [
                'title' => mb_substr((string) ($p['title'] ?? ''), 0, 100),
                'price' => (int) ($p['price'] ?? 0),
                'purchase6m' => (int) ($p['purchase6m'] ?? 0),
                'revenue6m' => $rev($p),
                'mallName' => (string) ($p['mallName'] ?? ''),
                'mallCount' => (int) ($p['mallCount'] ?? 0) ?: null,
                'sellerCount' => (int) ($p['sellerCount'] ?? 0) ?: null,
                'isAd' => ! empty($p['isAd']),
                'isCatalog' => ! empty($p['isCatalog']),
                'link' => (string) ($p['link'] ?? ''),
            ], array_slice($byRevenue, 0, 10)),
            'generated_by' => 'ext_bulk_serp',   // 확장 벌크 수집산(광고 제외 오가닉 기준)
        ];

        return MarketAnalysis::updateOrCreate(
            ['user_id' => $user->id, 'keyword' => $keyword],
            [
                'total_count' => max(0, $totalCount),
                'item_count' => count($items),
                'include_ads' => false,
                'sales_6m' => (int) $sales6m,
                'revenue_6m' => (int) $revenue6m,
                'avg_price' => $avg,
                'median_price' => $median,
                'top10_share' => $revenue6m > 0 ? round(min(100, $top10Revenue / $revenue6m * 100), 2) : 0,
                'monthly_search' => null,
                'comp_idx' => null,
                'snapshot' => $snapshot,
            ],
        );
    }
}
