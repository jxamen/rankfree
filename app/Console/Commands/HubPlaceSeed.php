<?php

namespace App\Console\Commands;

use App\Domain\Keyword\KeywordHubCollector;
use App\Models\KeywordCandidate;
use App\Models\KeywordCategory;
use App\Models\KeywordSearch;
use Illuminate\Console\Command;

/**
 * 키워드 허브 — 플레이스 지역×업종 조합 후보 생성(22).
 * pcmap 업종 6종 기준으로 전국 지역 × 업종 패턴을 조합해 "{지역} {패턴}" 후보를 만든다.
 *   지역 풀 = 큐레이션(database/data/place_keyword_matrix.php: 핫플레이스·여행지·구·시·주요 동)
 *            + 전국 행정구역(database/data/regions_kr.php: 시군구 364·읍면동 2,436 — 자동 생성)
 *   패턴 128종 × 지역 ~2,900 ≈ 조합 가능 총량 약 35만.
 * 검색량은 미상(pending) — 발행 시 분석이 볼륨을 판정(없으면 자동 보류)해 저품질 대량 발행을 막는다.
 * 대량 처리: 기존 키워드 사전 로드 + 500건 배치 insert. --limit 만큼 증분(재실행 시 이어서).
 */
class HubPlaceSeed extends Command
{
    protected $signature = 'hub:place-seed
        {--category= : 특정 업종만(restaurant|hospital|hairshop|nailshop|accommodation|place)}
        {--limit=2000 : 이번 실행 신규 후보 상한(재실행 시 이어서 추가)}';

    protected $description = '키워드 허브 — 플레이스 지역×업종 조합 키워드 후보 생성(22)';

    public function handle(\App\Domain\Keyword\PlaceKeywordPatterns $patterns): int
    {
        $matrix = require database_path('data/place_keyword_matrix.php');
        $regions = $this->regionPools($matrix['regions']);
        $patternMap = $patterns->all();   // 관리자 환경설정(플레이스 패턴 탭)에서 넣고 뺀 목록이 우선
        $keys = $this->option('category') ? [(string) $this->option('category')] : array_keys($matrix['categories']);
        $limit = max(1, (int) $this->option('limit'));

        $published = KeywordSearch::where('origin', 'hub')->pluck('keyword')->flip()->all();

        $created = 0;
        $skipped = 0;
        foreach ($keys as $i => $key) {
            $def = $matrix['categories'][$key] ?? null;
            if (! $def) {
                $this->error("알 수 없는 업종: {$key}");

                return self::FAILURE;
            }
            $cat = KeywordCategory::firstOrCreate(
                ['type' => 'place', 'name' => $def['name']],
                ['slug' => KeywordCategory::makeSlug($def['name']), 'sort' => $i + 1, 'is_active' => true],
            );
            // 이 카테고리의 기존 후보 키워드 사전 로드 — 조합당 exists 쿼리 제거(대량 처리)
            $existing = KeywordCandidate::where('category_id', $cat->id)->pluck('keyword')->flip()->all();

            $batch = [];
            $now = now();
            $flush = function () use (&$batch) {
                if ($batch) {
                    KeywordCandidate::insert($batch);
                    $batch = [];
                }
            };

            $catPatterns = $patternMap[$key]['patterns'] ?? $def['patterns'];
            foreach ($def['region_types'] as $rt) {
                foreach ((array) ($regions[$rt] ?? []) as $region) {
                    foreach ($catPatterns as $pattern) {
                        if ($created >= $limit) {
                            $flush();
                            $this->info("상한 도달 — 신규 {$created} · 제외 {$skipped}. 재실행하면 이어서 추가됩니다.");

                            return self::SUCCESS;
                        }
                        $kw = trim($region.' '.$pattern);
                        if (isset($existing[$kw]) || isset($published[$kw]) || ! KeywordHubCollector::acceptableKeyword($kw)) {
                            $skipped++;

                            continue;
                        }
                        $existing[$kw] = true;
                        $batch[] = [
                            'category_id' => $cat->id,
                            'region' => $region,       // 지역명(강남·성수동…) — 플레이스 2번째 분류 축
                            'region_type' => $rt,      // hotplace|district|city|dong|travel
                            'keyword' => $kw,
                            'source' => 'combo',
                            'status' => 'pending',
                            'note' => "지역×업종 조합({$rt})",
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                        $created++;
                        if (count($batch) >= 500) {
                            $flush();
                        }
                    }
                }
            }
            $flush();
            $this->info("[{$def['name']}] 누적 신규 {$created} · 제외 {$skipped}");
        }

        $this->info("완료 — 신규 후보 {$created} · 제외(기존/필터) {$skipped}");

        return self::SUCCESS;
    }

    /** 큐레이션 지역 + 전국 행정구역(regions_kr.php — 시군구→city, 읍면동→dong) 병합. 큐레이션이 우선순위 앞. */
    private function regionPools(array $curated): array
    {
        $path = database_path('data/regions_kr.php');
        if (is_file($path)) {
            $kr = require $path;
            $curated['city'] = array_values(array_unique(array_merge($curated['city'], (array) ($kr['sgg'] ?? []))));
            $curated['dong'] = array_values(array_unique(array_merge($curated['dong'], (array) ($kr['emd'] ?? []))));
        }

        return $curated;
    }
}
