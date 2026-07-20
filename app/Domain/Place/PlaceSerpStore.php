<?php

namespace App\Domain\Place;

use Illuminate\Support\Facades\DB;

/**
 * 플레이스 SERP 저장 — 업체는 마스터에 1회만, 키워드↔업체는 순위 매핑으로.
 *
 * 키워드마다 업체 300개를 통째로 저장하면 전량 52.8GB·2억 행이 된다(실측 82.3KB/건).
 * 같은 업체가 여러 키워드에 반복 노출되므로 업체를 place_id 로 합치고 매핑만 쌓는다.
 * 매핑은 collected_month 월별 파티션이라 특정 월만 조회·삭제(DROP PARTITION)할 수 있다.
 */
class PlaceSerpStore
{
    /**
     * SERP 결과 저장 — 업체 upsert + 그 달의 순위 매핑 교체.
     *
     * @param  array  $items  PlaceRankChecker::serpFetch 의 items
     * @return int 저장한 매핑 수
     */
    public function save(string $keyword, string $cat, array $items): int
    {
        if (! $items) {
            return 0;
        }
        $now = now();
        $month = (int) $now->format('Ym');

        // ── 업체 마스터 upsert(중복 제거의 핵심) ──
        $biz = [];
        foreach ($items as $i) {
            $pid = (string) ($i['place_id'] ?? '');
            if ($pid === '' || isset($biz[$pid])) {
                continue;
            }
            $biz[$pid] = [
                'place_id' => $pid,
                'name' => mb_substr((string) ($i['name'] ?? ''), 0, 191),
                'address' => mb_substr((string) ($i['address'] ?? ''), 0, 191) ?: null,
                'x' => $i['x'] ?? null,
                'y' => $i['y'] ?? null,
                'common_address' => mb_substr((string) ($i['common_address'] ?? ''), 0, 120) ?: null,
                'visitor_cnt' => $i['visitor_cnt'] ?? null,
                'blog_cnt' => $i['blog_cnt'] ?? null,
                'booking_cnt' => $i['booking_cnt'] ?? null,
                'save_cnt' => $i['save_cnt'] ?? null,
                'img_cnt' => $i['img_cnt'] ?? null,
                'review_score' => $i['review_score'] ?? null,
                'place_plus' => ! empty($i['place_plus']),
                'new_opening' => ! empty($i['new_opening']),
                'talktalk_id' => ($i['talktalk_id'] ?? '') ?: null,
                'talktalk_url' => mb_substr((string) ($i['talktalk_url'] ?? ''), 0, 255) ?: null,
                'seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk(array_values($biz), 200) as $chunk) {
            DB::table('place_businesses')->upsert($chunk, ['place_id'], [
                'name', 'address', 'x', 'y', 'common_address', 'visitor_cnt', 'blog_cnt', 'booking_cnt', 'save_cnt', 'img_cnt',
                'review_score', 'place_plus', 'new_opening', 'talktalk_id', 'talktalk_url', 'seen_at', 'updated_at',
            ]);
        }

        // ── 이번 달 매핑 교체(같은 달 재수집이면 갱신) ──
        DB::table('keyword_place_ranks')
            ->where('keyword', $keyword)->where('cat', $cat)->where('collected_month', $month)
            ->delete();

        $rows = [];
        foreach ($items as $i) {
            $pid = (string) ($i['place_id'] ?? '');
            if ($pid === '') {
                continue;
            }
            $rows[] = [
                'keyword' => $keyword,
                'cat' => $cat,
                'place_id' => $pid,
                'rnk' => (int) ($i['rnk'] ?? 0),
                'collected_month' => $month,
                'collected_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('keyword_place_ranks')->insert($chunk);
        }

        // 목록의 '수집일' 필터·정렬은 키워드 마스터 인덱스를 탄다 — 수집 즉시 반영
        app(\App\Domain\Keyword\KeywordMasterSync::class)->touchSerp($keyword, 'place', count($rows));

        // 허브 문서 대표 좌표(place_x/y) 갱신 — 상위 업체 좌표의 중앙값(지리 "주변 추천"용).
        // 중앙값은 이상치에 강하다. 최소 3곳 이상 좌표가 있을 때만 대표성을 인정한다.
        $xs = [];
        $ys = [];
        foreach ($items as $i) {
            if (! empty($i['x']) && ! empty($i['y'])) {
                $xs[] = (float) $i['x'];
                $ys[] = (float) $i['y'];
            }
        }
        if (count($xs) >= 3) {
            sort($xs);
            sort($ys);
            $mid = intdiv(count($xs), 2);
            DB::table('keyword_searches')->where('origin', 'hub')->where('keyword', $keyword)
                ->update(['place_x' => $xs[$mid], 'place_y' => $ys[$mid]]);
        }

        return count($rows);
    }

    /** 키워드의 최신 수집분(업체 정보 조인) — 없으면 빈 컬렉션. */
    public function items(string $keyword, string $cat, ?int $month = null)
    {
        $month ??= DB::table('keyword_place_ranks')
            ->where('keyword', $keyword)->where('cat', $cat)
            ->max('collected_month');
        if (! $month) {
            return collect();
        }

        return DB::table('keyword_place_ranks as r')
            ->join('place_businesses as b', 'b.place_id', '=', 'r.place_id')
            ->where('r.keyword', $keyword)->where('r.cat', $cat)->where('r.collected_month', $month)
            ->orderBy('r.rnk')
            ->select('r.rnk', 'r.collected_at', 'b.*')
            ->get();
    }

    /** 이 키워드가 수집된 월 목록(최신순) — 화면에서 월을 골라 볼 수 있게. */
    public function months(string $keyword, string $cat): array
    {
        return DB::table('keyword_place_ranks')
            ->where('keyword', $keyword)->where('cat', $cat)
            ->distinct()->orderByDesc('collected_month')
            ->pluck('collected_month')->all();
    }

    /** 특정 월 수집분 삭제(키워드 단위). */
    public function deleteMonth(string $keyword, string $cat, int $month): int
    {
        return DB::table('keyword_place_ranks')
            ->where('keyword', $keyword)->where('cat', $cat)->where('collected_month', $month)
            ->delete();
    }

    /** 월 전체를 통째로 비운다 — MariaDB 는 파티션 DROP(즉시), 그 외는 DELETE. */
    public function dropMonth(int $month): string
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $n = DB::table('keyword_place_ranks')->where('collected_month', $month)->delete();

            return "{$n}행 삭제";
        }
        $p = 'p'.$month;
        $exists = DB::selectOne(
            'SELECT COUNT(*) c FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME = ?',
            ['keyword_place_ranks', $p]
        );
        if (! $exists || ! $exists->c) {
            return '해당 월 파티션 없음';
        }
        DB::statement("ALTER TABLE keyword_place_ranks DROP PARTITION {$p}");

        return "파티션 {$p} 삭제";
    }
}
