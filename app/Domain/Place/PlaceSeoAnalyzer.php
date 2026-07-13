<?php

namespace App\Domain\Place;

use App\Models\PlaceRankSlot;
use App\Models\PlaceSeoDaily;
use App\Models\PlaceSeoScore;
use App\Models\PlaceSeoSerp;

/**
 * 경쟁분석 오케스트레이션 — crm spa_analyze_track 이식.
 *
 * 트랙(순위추적 슬롯) 1개 분석:
 *   T1 경쟁셋 수집(상위30) → T2 상세(내+상위 detailTop) + 리뷰 주별수집(내+상위 reviewTop)
 *   → 점수(D1~D10 · N1/N2/N3) → 일별 스냅샷 저장(멱등 upsert).
 */
class PlaceSeoAnalyzer
{
    public function __construct(private PlaceRankChecker $checker) {}

    /** @return array{blocked:bool, my_rank:int, total:int, competitors:int, my_score:?array} */
    public function analyze(PlaceRankSlot $slot, int $detailTop = 10, int $reviewTop = 10): array
    {
        $keyword = trim($slot->keyword);
        $cat = $slot->category ?: 'place';
        $myPid = $slot->place_id ? preg_replace('/\D/', '', $slot->place_id) : null;
        $ymd = now()->toDateString();

        $serp = $this->checker->serpFetch($keyword, $cat, $myPid, 30);
        if ($serp['blocked']) {
            return ['blocked' => true, 'my_rank' => 300, 'total' => 0, 'competitors' => 0, 'my_score' => null];
        }

        $items = $serp['items'];
        $total = (int) $serp['total'];
        $myRank = (int) $serp['my_rank'];

        // 정규화 모집단(상위30)
        $visArr = array_map(fn ($i) => $i['visitor_cnt'], $items);
        $blogArr = array_map(fn ($i) => $i['blog_cnt'], $items);
        $saveArr = array_map(fn ($i) => $i['save_cnt'], $items);
        $scoreArr = array_map(fn ($i) => $i['review_score'], $items);
        $bookingArr = array_map(fn ($i) => $i['booking_cnt'], $items);
        $imgArr = array_map(fn ($i) => $i['img_cnt'] ?? null, $items);

        // T2 상세 + 리뷰 주별수집(D9/D10 raw)
        $details = [];
        $recArr = [];
        $authArr = [];
        $bvArr = [];
        $mineInList = false;
        foreach ($items as $it) {
            $pid = $it['place_id'];
            $isMine = ($myPid && $pid === $myPid);
            if ($isMine) {
                $mineInList = true;
            }
            $needDetail = ($it['rnk'] <= $detailTop || $isMine);
            $needReview = ($it['rnk'] <= $reviewTop || $isMine);
            if ($needDetail || $needReview) {
                $details[$pid] = $this->checker->placeDetailFull($pid, $cat);
            }
            if ($needReview) {
                $this->attachReview($details[$pid], $pid, $cat, $ymd, $recArr, $authArr, $bvArr);
            }
        }

        $myScore = null;
        foreach ($items as $it) {
            $pid = $it['place_id'];
            $detail = $details[$pid] ?? null;
            $isMine = ($myPid && $pid === $myPid);
            $sc = PlaceScorer::computeScores($it, $detail, $keyword, $cat, $visArr, $blogArr, $saveArr, $scoreArr, $bookingArr, $recArr, $authArr, $bvArr, $imgArr);
            $this->saveSerp($slot->id, $ymd, $it, $isMine, $total);
            $this->saveScore($slot->id, $ymd, $pid, $it['rnk'], $sc, $isMine);
            if ($detail && $detail['ok']) {
                $this->saveDaily($pid, $ymd, $it, $detail);
            }
            if ($isMine) {
                $myScore = $sc + ['rnk' => $it['rnk']];
            }
        }

        // 내 매장이 상위30 밖 → 별도 수집·저장
        if ($myPid && ! $mineInList) {
            $detail = $this->checker->placeDetailFull($myPid, $cat);
            $this->attachReview($detail, $myPid, $cat, $ymd, $recArr, $authArr, $bvArr);
            $item = [
                'rnk' => $myRank ?: 300, 'place_id' => $myPid,
                'name' => $slot->place_name ?: ($detail['name'] ?? ''),
                'visitor_cnt' => $detail['visitor_cnt'] ?? null, 'blog_cnt' => $detail['blog_cnt'] ?? null,
                'booking_cnt' => null, 'save_cnt' => null, 'img_cnt' => null, 'review_score' => $detail['review_score'] ?? null,
                'tags' => $detail['tags'] ?? [], 'address' => '',
            ];
            $sc = PlaceScorer::computeScores($item, $detail, $keyword, $cat, $visArr, $blogArr, $saveArr, $scoreArr, $bookingArr, $recArr, $authArr, $bvArr, $imgArr);
            $this->saveSerp($slot->id, $ymd, $item, true, $total);
            $this->saveScore($slot->id, $ymd, $myPid, $item['rnk'], $sc, true);
            if (! empty($detail['ok'])) {
                $this->saveDaily($myPid, $ymd, $item, $detail);
            }
            $myScore = $sc + ['rnk' => $item['rnk']];
        }

        return ['blocked' => false, 'my_rank' => $myRank, 'total' => $total, 'competitors' => count($items), 'my_score' => $myScore];
    }

    /** 리뷰 주별수집 → rec_raw(D9)·auth_raw(D10)·bv_raw(D3대체) 를 $detail 에 부착 + 정규화 배열 push. */
    private function attachReview(array &$detail, string $pid, string $cat, string $ymd, array &$recArr, array &$authArr, array &$bvArr): void
    {
        $wk = $this->checker->reviewWeekly($pid, $cat, $ymd);
        $rec = (int) ($wk['v'][3] ?? 0) + (int) ($wk['b'][3] ?? 0);
        $bv = (int) ($wk['quality']['ctx']['예약 후 이용'] ?? 0);
        $auth = null;
        if (! empty($wk['quality']['authority'])) {
            $au = $wk['quality']['authority'];
            $auth = $au['infl'] * 4 + $au['hi_infl'] * 3 + $au['power'] * 1.5 + min(20, $au['avg_fol'] / 10) + min(8, $bv * 0.8);
        }
        $detail['rec_raw'] = $rec;
        $detail['auth_raw'] = $auth;
        $detail['bv_raw'] = $bv;
        $detail['review_weekly'] = ['v' => $wk['v'], 'b' => $wk['b']];
        $detail['review_quality'] = $wk['quality'];

        $recArr[] = $rec;
        if ($auth !== null) {
            $authArr[] = $auth;
        }
        $bvArr[] = $bv;
    }

    private function saveSerp(int $slotId, string $ymd, array $it, bool $isMine, int $total): void
    {
        PlaceSeoSerp::updateOrCreate(
            ['slot_id' => $slotId, 'ymd' => $ymd, 'rnk' => (int) $it['rnk'], 'is_mine' => $isMine],
            [
                'place_id' => (string) $it['place_id'], 'name' => (string) ($it['name'] ?? ''),
                'visitor_cnt' => $it['visitor_cnt'] ?? null, 'blog_cnt' => $it['blog_cnt'] ?? null,
                'booking_cnt' => $it['booking_cnt'] ?? null, 'save_cnt' => $it['save_cnt'] ?? null,
                'image_cnt' => $it['img_cnt'] ?? null,
                'review_score' => $it['review_score'] ?? null, 'tags' => $it['tags'] ?? [],
                'address' => (string) ($it['address'] ?? ''), 'list_total' => $total, 'created_at' => now(),
            ],
        );
    }

    private function saveScore(int $slotId, string $ymd, string $pid, int $rnk, array $sc, bool $isMine): void
    {
        PlaceSeoScore::updateOrCreate(
            ['slot_id' => $slotId, 'place_id' => $pid, 'ymd' => $ymd],
            [
                'rnk' => $rnk,
                'd1' => $sc['d1'], 'd2' => $sc['d2'], 'd3' => $sc['d3'], 'd4' => $sc['d4'], 'd5' => $sc['d5'], 'd6' => $sc['d6'],
                'd7' => $sc['d7'], 'd8' => $sc['d8'], 'd9' => $sc['d9'], 'd10' => $sc['d10'],
                'n1' => $sc['n1'], 'n2' => $sc['n2'], 'n3' => $sc['n3'],
                'avail_mask' => $sc['mask'], 'tier' => $sc['tier'], 'is_mine' => $isMine, 'created_at' => now(),
            ],
        );
    }

    private function saveDaily(string $pid, string $ymd, array $it, array $d): void
    {
        PlaceSeoDaily::updateOrCreate(
            ['place_id' => $pid, 'ymd' => $ymd],
            [
                'name' => (string) ($d['name'] ?: ($it['name'] ?? '')), 'category' => (string) ($d['category'] ?? ''),
                'visitor_cnt' => $d['visitor_cnt'] ?? ($it['visitor_cnt'] ?? null),
                'blog_cnt' => $d['blog_cnt'] ?? ($it['blog_cnt'] ?? null),
                'booking_cnt' => $it['booking_cnt'] ?? null, 'save_cnt' => $it['save_cnt'] ?? null,
                'review_score' => $d['review_score'] ?? ($it['review_score'] ?? null),
                'menu_cnt' => $d['menu_cnt'], 'photo_cnt' => $d['photo_cnt'], 'conv_cnt' => $d['conv_cnt'],
                'pay_cnt' => $d['pay_cnt'], 'keyword_cnt' => $d['keyword_cnt'], 'category_cnt' => $d['category_cnt'],
                'stylist_cnt' => $d['stylist_cnt'], 'has_road' => $d['has_road'], 'has_talktalk' => $d['has_talktalk'],
                'has_chatbot' => $d['has_chatbot'], 'has_booking' => $d['has_booking'], 'hide_hours' => $d['hide_hours'],
                'hide_price' => $d['hide_price'], 'missing_cnt' => $d['missing_cnt'],
                'missing_labels' => implode(',', (array) ($d['missing_labels'] ?? [])),
                'place_plus' => $d['place_plus'], 'tags' => $d['tags'] ?? [], 'review_kw' => $d['review_kw'] ?? null,
                'review_weekly' => $d['review_weekly'] ?? null, 'review_quality' => $d['review_quality'] ?? null,
                'created_at' => now(),
            ],
        );
    }
}
