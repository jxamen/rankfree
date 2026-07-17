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

        return count($rows);
    }

    /** 상품 식별자 — nvMid(링크의 nvMid=…) 우선, 없으면 링크/제목 해시. */
    private function productKey(array $p): string
    {
        $link = (string) ($p['link'] ?? '');
        if ($link !== '' && preg_match('/nvMid=(\d+)/', $link, $m)) {
            return 'nv'.$m[1];
        }
        if ($link !== '' && preg_match('#/products/(\d+)#', $link, $m)) {
            return 'pd'.$m[1];
        }
        $basis = $link !== '' ? $link : (string) ($p['title'] ?? '');

        return $basis === '' ? '' : 'h'.substr(sha1($basis), 0, 32);
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
