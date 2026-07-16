<?php

namespace App\Console\Commands;

use App\Domain\Keyword\KeywordHubCollector;
use App\Models\KeywordCandidate;
use App\Models\KeywordCategory;
use App\Models\KeywordSearch;
use Illuminate\Console\Command;

/**
 * 키워드 허브 — 플레이스 지역×업종 조합 후보 생성(22).
 * pcmap 업종 6종 기준으로 전국 지역(핫플레이스·여행지·구·시·주요 동) × 업종 패턴을 조합해
 * "{지역} {패턴}"(강남 치과, 성수동 맛집, 제주도 호텔 …) 후보를 만든다.
 * 매트릭스: database/data/place_keyword_matrix.php (편집 후 재실행하면 신규분만 추가).
 * 검색량은 미상(pending) — 발행 시 분석이 볼륨을 판정(없으면 자동 보류)해 저품질 대량 발행을 막는다.
 */
class HubPlaceSeed extends Command
{
    protected $signature = 'hub:place-seed
        {--category= : 특정 업종만(restaurant|hospital|hairshop|nailshop|accommodation|place)}
        {--limit=2000 : 이번 실행 신규 후보 상한(재실행 시 이어서 추가)}';

    protected $description = '키워드 허브 — 플레이스 지역×업종 조합 키워드 후보 생성(22)';

    public function handle(): int
    {
        $matrix = require database_path('data/place_keyword_matrix.php');
        $keys = $this->option('category') ? [(string) $this->option('category')] : array_keys($matrix['categories']);
        $limit = max(1, (int) $this->option('limit'));

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

            foreach ($def['region_types'] as $rt) {
                foreach ((array) ($matrix['regions'][$rt] ?? []) as $region) {
                    foreach ($def['patterns'] as $pattern) {
                        if ($created >= $limit) {
                            $this->info("상한 도달 — 신규 {$created} · 제외 {$skipped}. 재실행하면 이어서 추가됩니다.");

                            return self::SUCCESS;
                        }
                        $kw = trim($region.' '.$pattern);
                        if (! KeywordHubCollector::acceptableKeyword($kw)
                            || KeywordSearch::where('origin', 'hub')->where('keyword', $kw)->exists()
                            || KeywordCandidate::where('category_id', $cat->id)->where('keyword', $kw)->exists()) {
                            $skipped++;

                            continue;
                        }
                        KeywordCandidate::create([
                            'category_id' => $cat->id,
                            'keyword' => $kw,
                            'source' => 'combo',
                            'status' => 'pending',
                            'note' => "지역×업종 조합({$rt})",
                        ]);
                        $created++;
                    }
                }
            }
            $this->info("[{$def['name']}] 누적 신규 {$created} · 제외 {$skipped}");
        }

        $this->info("완료 — 신규 후보 {$created} · 제외(기존/필터) {$skipped}");

        return self::SUCCESS;
    }
}
