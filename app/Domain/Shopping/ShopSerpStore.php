<?php

namespace App\Domain\Shopping;

use Illuminate\Support\Facades\DB;

/**
 * 쇼핑 SERP 저장 — 상품은 마스터에 1회만, 키워드↔상품은 순위 매핑으로(플레이스와 동일 구조).
 *
 * 여러 키워드에 같은 상품이 반복 노출되므로 상품을 product_key 로 합치고 매핑만 쌓는다.
 * 매핑은 collected_month 월별 파티션이라 특정 월만 조회·삭제(DROP PARTITION)할 수 있다.
 * 저장 범위는 공개 노출분(상품명·가격·몰 이름·광고 여부·순위)뿐 — 판매자 개인정보는 다루지 않는다.
 */
class ShopSerpStore
{
    /**
     * @param  array  $products  [{title, rank, price, mallName, link, isAd}]
     * @return int 저장한 매핑 수
     */
    public function save(string $keyword, array $products): int
    {
        if (! $products) {
            return 0;
        }
        $now = now();
        $month = (int) $now->format('Ym');

        $prod = [];
        $rows = [];
        $malls = [];
        foreach ($products as $p) {
            // 스마트스토어(brand 포함) 상품만 저장한다 — 외부몰(현대Hmall 등)은 톡톡·판매자 정보가 없어
            // 이후 분석에 쓸 수 없다. 순위(rnk)는 네이버 원본 그대로 두므로 번호는 띄엄띄엄해진다.
            if (! $this->isSmartStore($p)) {
                continue;
            }
            $key = $this->productKey($p);
            if ($key === '') {
                continue;
            }
            $link = (string) ($p['link'] ?? '');
            if (mb_strlen($link) > 2000) {   // 광고 링크(cr.shopping.naver.com/adcr)는 2,000자를 넘는다
                $link = mb_substr($link, 0, 2000);
            }
            $mall = mb_substr((string) ($p['mallName'] ?? ''), 0, 120);

            $prod[$key] = [
                'product_key' => $key,
                'title' => mb_substr((string) ($p['title'] ?? ''), 0, 300),
                'price' => (int) ($p['price'] ?? 0) ?: null,
                'mall_name' => $mall ?: null,
                'talk_id' => mb_substr((string) ($p['talkId'] ?? ''), 0, 60) ?: null,   // 판매처 톡톡
                'link' => $link ?: null,
                'is_ad' => ! empty($p['isAd']),
                'seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $rows[] = [
                'keyword' => $keyword,
                'product_key' => $key,
                'rnk' => (int) ($p['rank'] ?? 0),
                'is_ad' => ! empty($p['isAd']),
                'collected_month' => $month,
                'collected_at' => $now,
            ];
            if ($mall !== '') {
                $malls[$mall] = ['mall_name' => $mall, 'seen_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        foreach (array_chunk(array_values($prod), 200) as $chunk) {
            DB::table('shop_products')->upsert($chunk, ['product_key'], ['title', 'price', 'mall_name', 'talk_id', 'link', 'is_ad', 'seen_at', 'updated_at']);
        }
        if ($malls) {
            DB::table('shop_malls')->upsert(array_values($malls), ['mall_name'], ['seen_at', 'updated_at']);
        }

        // 이번 달 매핑 교체(같은 달 재수집이면 갱신)
        DB::table('keyword_shop_ranks')->where('keyword', $keyword)->where('collected_month', $month)->delete();
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('keyword_shop_ranks')->insert($chunk);
        }

        // 목록의 '수집일' 필터·정렬은 키워드 마스터 인덱스를 탄다 — 수집 즉시 반영
        app(\App\Domain\Keyword\KeywordMasterSync::class)->touchSerp($keyword, 'shopping', count($rows));

        return count($rows);
    }

    /**
     * 스마트스토어 상품인가 — 상품 URL 이 smartstore/brand.naver.com 인 것만.
     * 확장이 mallPcUrl(실제 스토어 핸들)로 상품 URL 을 재구성하므로 링크 도메인이 곧 스토어 유형이다.
     */
    private function isSmartStore(array $p): bool
    {
        $link = (string) ($p['link'] ?? '');
        if (preg_match('#https?://(smartstore|brand)\.naver\.com/#i', $link)) {
            return true;
        }

        // 확장이 스토어 핸들을 따로 넘겨주면 그것으로도 인정(링크가 광고 리다이렉트인 경우)
        return trim((string) ($p['storeId'] ?? '')) !== '';
    }

    /** 상품 식별자 — nvMid(링크의 nvMid=…) 우선, 없으면 제목+판매처 해시. */
    private function productKey(array $p): string
    {
        $link = (string) ($p['link'] ?? '');
        if ($link !== '' && preg_match('/nvMid=(\d+)/', $link, $m)) {
            return 'nv'.$m[1];
        }
        if ($link !== '' && preg_match('#/products/(\d+)#', $link, $m)) {
            return 'pd'.$m[1];
        }
        // 광고는 링크가 cr.shopping.naver.com/adcr?x=… 라 상품 id 가 없고, 매 수집마다 x 값이 달라진다.
        // 링크를 해시하면 같은 상품이 매번 새 행으로 쌓이고, 반대로 링크가 같으면 다른 상품이 한 행으로 합쳐진다.
        // → 제목+판매처로 식별한다(광고 상품에서 이 둘이 같으면 사실상 같은 노출).
        $basis = trim((string) ($p['title'] ?? '')).'|'.trim((string) ($p['mallName'] ?? ''));

        return trim($basis, '|') === '' ? '' : 'h'.substr(sha1($basis), 0, 32);
    }

    /** 키워드의 수집분(상품 조인) — month 미지정이면 최신 월. */
    public function items(string $keyword, ?int $month = null)
    {
        $month ??= DB::table('keyword_shop_ranks')->where('keyword', $keyword)->max('collected_month');
        if (! $month) {
            return collect();
        }

        return DB::table('keyword_shop_ranks as r')
            ->join('shop_products as p', 'p.product_key', '=', 'r.product_key')
            ->where('r.keyword', $keyword)->where('r.collected_month', $month)
            ->orderBy('r.rnk')
            ->select('r.rnk', 'r.collected_at', 'r.is_ad', 'p.title', 'p.price', 'p.mall_name', 'p.talk_id', 'p.link', 'p.product_key')
            ->get();
    }

    /** 수집된 월 목록(최신순). */
    public function months(string $keyword): array
    {
        return DB::table('keyword_shop_ranks')->where('keyword', $keyword)
            ->distinct()->orderByDesc('collected_month')->pluck('collected_month')->all();
    }

    /** 특정 월 수집분 삭제(키워드 단위) — 상품 마스터는 남긴다. */
    public function deleteMonth(string $keyword, int $month): int
    {
        return DB::table('keyword_shop_ranks')->where('keyword', $keyword)->where('collected_month', $month)->delete();
    }

    /** 월 전체 정리 — MariaDB 는 파티션 DROP(즉시). */
    public function dropMonth(int $month): string
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return DB::table('keyword_shop_ranks')->where('collected_month', $month)->delete().'행 삭제';
        }
        $p = 'p'.$month;
        $ex = DB::selectOne(
            'SELECT COUNT(*) c FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME = ?',
            ['keyword_shop_ranks', $p]
        );
        if (! $ex || ! $ex->c) {
            return '해당 월 파티션 없음';
        }
        DB::statement("ALTER TABLE keyword_shop_ranks DROP PARTITION {$p}");

        return "파티션 {$p} 삭제";
    }
}
