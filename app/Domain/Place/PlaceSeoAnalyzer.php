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
 *   T1 경쟁셋 수집(상위30) → T2 상세(내+상위 detailTop) → 점수 → 일별 스냅샷 저장(멱등 upsert).
 * D9/D10(리뷰 주별/영향력)은 task D 에서 review_weekly 추가. 현재는 null(가중 재정규화로 자연 제외).
 */
class PlaceSeoAnalyzer
{
    public function __construct(private PlaceRankChecker $checker) {}

    /** @return array{blocked:bool, my_rank:int, total:int, competitors:int, my_score:?array} */
    public function analyze(PlaceRankSlot $slot, int $detailTop = 10): array
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

        // T2 상세: 내 매장 + 상위 detailTop
        $details = [];
        $mineInList = false;
        foreach ($items as $it) {
            $isMine = ($myPid && $it['place_id'] === $myPid);
            if ($isMine) {
                $mineInList = true;
            }
            if ($it['rnk'] <= $detailTop || $isMine) {
                $details[$it['place_id']] = $this->checker->placeDetailFull($it['place_id'], $cat);
            }
        }

        $myScore = null;
        foreach ($items as $it) {
            $pid = $it['place_id'];
            $detail = $details[$pid] ?? null;
            $isMine = ($myPid && $pid === $myPid);
            $sc = PlaceScorer::computeScores($it, $detail, $keyword, $cat, $visArr, $blogArr, $saveArr, $scoreArr, $bookingArr);
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
            $item = [
                'rnk' => $myRank ?: 300, 'place_id' => $myPid,
                'name' => $slot->place_name ?: ($detail['name'] ?? ''),
                'visitor_cnt' => $detail['visitor_cnt'] ?? null, 'blog_cnt' => $detail['blog_cnt'] ?? null,
                'booking_cnt' => null, 'save_cnt' => null, 'review_score' => $detail['review_score'] ?? null,
                'tags' => $detail['tags'] ?? [], 'address' => '',
            ];
            $sc = PlaceScorer::computeScores($item, $detail, $keyword, $cat, $visArr, $blogArr, $saveArr, $scoreArr, $bookingArr);
            $this->saveSerp($slot->id, $ymd, $item, true, $total);
            $this->saveScore($slot->id, $ymd, $myPid, $item['rnk'], $sc, true);
            if (! empty($detail['ok'])) {
                $this->saveDaily($myPid, $ymd, $item, $detail);
            }
            $myScore = $sc + ['rnk' => $item['rnk']];
        }

        return ['blocked' => false, 'my_rank' => $myRank, 'total' => $total, 'competitors' => count($items), 'my_score' => $myScore];
    }

    private function saveSerp(int $slotId, string $ymd, array $it, bool $isMine, int $total): void
    {
        PlaceSeoSerp::updateOrCreate(
            ['slot_id' => $slotId, 'ymd' => $ymd, 'rnk' => (int) $it['rnk'], 'is_mine' => $isMine],
            [
                'place_id' => (string) $it['place_id'], 'name' => (string) ($it['name'] ?? ''),
                'visitor_cnt' => $it['visitor_cnt'] ?? null, 'blog_cnt' => $it['blog_cnt'] ?? null,
                'booking_cnt' => $it['booking_cnt'] ?? null, 'save_cnt' => $it['save_cnt'] ?? null,
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
                'd1' => $sc['d1'], 'd2' => $sc['d2'], 'd3' => $sc['d3'], 'd4' => $sc['d4'], 'd5' => $sc['d5'],
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
                'created_at' => now(),
            ],
        );
    }
}
