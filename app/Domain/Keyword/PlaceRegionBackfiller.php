<?php

namespace App\Domain\Keyword;

use App\Models\KeywordCategory;
use Illuminate\Support\Facades\DB;

/**
 * 플레이스 region 백필(22) — region 컬럼(2026_07_16_000030) 이전에 만들어진
 * 후보·발행 문서는 region 이 NULL 이라 카테고리 허브의 지역 배지 수가 실제보다 적게 나온다.
 * (실측: '강남역 맛집' 등 14건 중 4건만 region 보유 → 배지 "강남역 4")
 * 키워드에서 지역을 되짚어 채운다. 재실행 안전(이미 채워진 행은 건드리지 않음).
 *
 * timestamps 는 건드리지 않는다(DB::table 직접 update) — 사이트맵 lastmod(updated_at) 를
 * 내용 변경 없이 갱신하면 색인에 거짓 신호가 된다.
 */
class PlaceRegionBackfiller
{
    public function __construct(private PlaceKeywordRegions $regions) {}

    /**
     * @return array{candidates:int,docs:int,unmatched:int}
     */
    public function run(bool $dryRun = false): array
    {
        $stats = ['candidates' => 0, 'docs' => 0, 'unmatched' => 0];

        foreach (KeywordCategory::where('type', 'place')->get(['id', 'name']) as $cat) {
            foreach ([
                'keyword_candidates' => 'candidates',
                'keyword_searches' => 'docs',
            ] as $table => $key) {
                DB::table($table)
                    ->select('id', 'keyword')
                    ->where('category_id', $cat->id)
                    ->whereNull('region')
                    ->when($table === 'keyword_searches', fn ($q) => $q->where('origin', 'hub'))
                    ->orderBy('id')
                    ->chunkById(500, function ($rows) use ($table, $key, $cat, &$stats, $dryRun) {
                        foreach ($rows as $r) {
                            $m = $this->regions->resolve((string) $r->keyword, $cat->name);
                            if (! $m) {
                                $stats['unmatched']++;

                                continue;
                            }
                            if (! $dryRun) {
                                DB::table($table)->where('id', $r->id)->update($m);
                            }
                            $stats[$key]++;
                        }
                    });
            }
        }

        return $stats;
    }
}
