<?php

namespace App\Domain\Keyword;

use App\Models\Keyword;
use App\Models\KeywordCandidate;
use App\Models\KeywordCategory;
use Illuminate\Support\Facades\DB;

/**
 * 키워드 마스터(keywords) 동기화 — candidates('키워드 × 분류' 매핑)에서 키워드 단위 행을 만든다.
 *
 * 마스터는 목록·정렬 전용 파생 테이블이라 언제든 rebuild() 로 다시 만들 수 있다.
 * 후보가 늘거나(시딩·수집) 상태가 바뀌면 여기를 거쳐 반영한다.
 */
class KeywordMasterSync
{
    /** 대표 상태 우선순위 — 가장 진행된 것을 키워드의 상태로 본다. */
    private const STATUS_RANK = ['rejected' => 1, 'pending' => 2, 'approved' => 3, 'published' => 4];

    /** 후보 한 건 기준으로 그 키워드의 마스터 행을 다시 계산한다(단건 경로: 발견·수집·발행). */
    public function syncOne(string $keyword, string $type): void
    {
        $catIds = KeywordCategory::where('type', $type)->pluck('id');
        $rows = KeywordCandidate::where('keyword', $keyword)->whereIn('category_id', $catIds)
            ->get(['category_id', 'region', 'region_type', 'source', 'monthly_total', 'comp_idx', 'volume_checked_at', 'status']);

        if ($rows->isEmpty()) {
            Keyword::where('keyword', $keyword)->where('type', $type)->delete();

            return;
        }

        Keyword::updateOrCreate(
            ['keyword' => $keyword, 'type' => $type],
            [
                'region' => $rows->pluck('region')->filter()->first(),
                'region_type' => $rows->pluck('region_type')->filter()->first(),
                'source' => $rows->pluck('source')->filter()->first(),
                'monthly_total' => $rows->max('monthly_total'),
                'comp_idx' => $rows->pluck('comp_idx')->filter()->first(),
                'volume_checked_at' => $rows->max('volume_checked_at'),
                'cat_cnt' => $rows->count(),
                'category_id' => $rows->first()->category_id,
                'status' => $this->topStatus($rows->pluck('status')->all()),
            ],
        );
    }

    /** 업체·상품 수집 결과를 마스터에 기록 — 목록의 '수집일' 필터·정렬이 인덱스를 타게 한다. */
    public function touchSerp(string $keyword, string $type, int $count, $at = null): void
    {
        Keyword::where('keyword', $keyword)->where('type', $type)
            ->update(['serp_collected_at' => $at ?: now(), 'serp_count' => $count]);
    }

    /**
     * 전체 재구축 — 시딩·대량 상태변경처럼 행을 우회해 쓰는 경로 뒤에 한 번 돌린다.
     * candidates 를 청크로 훑어 메모리에 접고 upsert 한다(운영 99만 행 기준).
     *
     * @return int 만들어진 키워드 수
     */
    public function rebuild(?string $type = null): int
    {
        $types = KeywordCategory::when($type, fn ($q) => $q->where('type', $type))->pluck('type', 'id');
        if ($types->isEmpty()) {
            return 0;
        }

        $rows = [];
        KeywordCandidate::whereIn('category_id', $types->keys())
            ->select('keyword', 'category_id', 'region', 'region_type', 'source', 'monthly_total', 'comp_idx', 'volume_checked_at', 'status')
            ->orderBy('id')
            ->chunk(5000, function ($chunk) use (&$rows, $types) {
                foreach ($chunk as $c) {
                    $t = $types[$c->category_id] ?? null;
                    if (! $t) {
                        continue;
                    }
                    $k = $c->keyword.'|'.$t;
                    if (! isset($rows[$k])) {
                        $rows[$k] = [
                            'keyword' => $c->keyword, 'type' => $t,
                            'region' => $c->region, 'region_type' => $c->region_type, 'source' => $c->source,
                            'monthly_total' => $c->monthly_total, 'comp_idx' => $c->comp_idx,
                            'volume_checked_at' => $c->volume_checked_at,
                            'cat_cnt' => 0, 'category_id' => $c->category_id, 'status' => $c->status,
                            'created_at' => now(), 'updated_at' => now(),
                        ];
                    }
                    $rows[$k]['cat_cnt']++;
                    if (($c->monthly_total ?? 0) > ($rows[$k]['monthly_total'] ?? 0)) {
                        $rows[$k]['monthly_total'] = $c->monthly_total;
                    }
                    if ($rows[$k]['region'] === null && $c->region !== null) {
                        $rows[$k]['region'] = $c->region;
                        $rows[$k]['region_type'] = $c->region_type;
                    }
                    if ((self::STATUS_RANK[$c->status] ?? 0) > (self::STATUS_RANK[$rows[$k]['status']] ?? 0)) {
                        $rows[$k]['status'] = $c->status;
                    }
                }
            });

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            Keyword::upsert($chunk, ['keyword', 'type'], [
                'region', 'region_type', 'source', 'monthly_total', 'comp_idx',
                'volume_checked_at', 'cat_cnt', 'category_id', 'status', 'updated_at',
            ]);
        }

        // 수집일·건수는 스냅샷이 기준(마스터는 캐시일 뿐) — 매핑 테이블에서 되돌려 채운다
        foreach (['place' => 'keyword_place_ranks', 'shopping' => 'keyword_shop_ranks'] as $t => $tbl) {
            if (($type && $type !== $t) || ! \Illuminate\Support\Facades\Schema::hasTable($tbl)) {
                continue;
            }
            DB::table($tbl)->selectRaw('keyword, MAX(collected_at) as at, COUNT(*) as c')
                ->groupBy('keyword')->orderBy('keyword')->chunk(1000, function ($chunk) use ($t) {
                    foreach ($chunk as $r) {
                        Keyword::where('keyword', $r->keyword)->where('type', $t)
                            ->update(['serp_collected_at' => $r->at, 'serp_count' => $r->c]);
                    }
                });
        }

        return count($rows);
    }

    private function topStatus(array $statuses): string
    {
        $best = 'pending';
        foreach ($statuses as $s) {
            if ((self::STATUS_RANK[$s] ?? 0) > (self::STATUS_RANK[$best] ?? 0)) {
                $best = $s;
            }
        }

        return $best;
    }
}
