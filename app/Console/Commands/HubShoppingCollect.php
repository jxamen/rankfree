<?php

namespace App\Console\Commands;

use App\Domain\Keyword\KeywordHubCollector;
use App\Domain\Keyword\NaverDataLabShoppingService;
use App\Models\KeywordCandidate;
use App\Models\KeywordCategory;
use App\Models\KeywordSearch;
use Illuminate\Console\Command;

/**
 * 키워드 허브 — 데이터랩 쇼핑인사이트 분야별 인기검색어 수집(22).
 * 1·2분류는 KeywordCategory 로 동기화(naver_cid 매핑)하고,
 * 2분류 + 그 하위 3분류의 인기검색어를 해당 2분류 카테고리의 후보(source=datalab)로 적재한다.
 * (허브 카테고리 트리는 2계층 유지 — 3분류 키워드는 소속 2분류로 흡수)
 */
class HubShoppingCollect extends Command
{
    protected $signature = 'hub:shopping-collect
        {--root= : 특정 1분류 cid 만(예: 50000001 패션잡화)}
        {--pages= : 분야당 인기검색어 페이지 수(페이지당 20개 · 최대 25=500위, 기본 config)}
        {--depth=3 : 인기검색어 수집 분류 깊이(2=2분류만, 3=3분류 포함)}
        {--delay-ms=300 : 분야 간 대기(ms)}';

    protected $description = '키워드 허브 — 데이터랩 쇼핑인사이트 분야별(1~3분류) 인기검색어 수집(22)';

    private int $created = 0;

    private int $skipped = 0;

    public function handle(NaverDataLabShoppingService $datalab): int
    {
        $roots = $datalab->children(0); // 1분류
        if (! $roots) {
            $this->error('데이터랩 1분류 조회 실패 — 응답 구조 변경 또는 차단 여부를 확인하세요.');

            return self::FAILURE;
        }
        if ($this->option('root')) {
            $roots = array_values(array_filter($roots, fn ($r) => $r['cid'] === (int) $this->option('root')));
        }

        $pages = (int) ($this->option('pages') ?: config('rankfree.hub.datalab_pages', 25));
        $depth = max(2, min(3, (int) $this->option('depth')));
        $delay = max(0, (int) $this->option('delay-ms')) * 1000;

        foreach ($roots as $i => $root) {
            $rootCat = $this->ensureCategory($root['cid'], $root['name'], null, $i + 1);

            foreach ($datalab->children($root['cid']) as $j => $sub) { // 2분류
                $subCat = $this->ensureCategory($sub['cid'], $sub['name'], $rootCat->id, $j + 1);

                usleep($delay);
                $this->ingest($subCat, $datalab->topKeywords($sub['cid'], $pages), $root['name'].' > '.$sub['name']);

                if ($depth >= 3 && ! $sub['leaf']) {
                    foreach ($datalab->children($sub['cid']) as $third) { // 3분류 → 2분류 카테고리로 흡수
                        usleep($delay);
                        $this->ingest($subCat, $datalab->topKeywords($third['cid'], $pages), $root['name'].' > '.$sub['name'].' > '.$third['name']);
                    }
                }
                $subCat->forceFill(['collected_at' => now()])->save();
            }
            $rootCat->forceFill(['collected_at' => now()])->save();
            $this->info("[{$root['name']}] 누적 신규 {$this->created} · 제외 {$this->skipped}");
        }

        $this->info("완료 — 신규 후보 {$this->created} · 제외(기존/필터) {$this->skipped}");

        return self::SUCCESS;
    }

    /** 인기검색어 → 후보(pending, source=datalab). 기존 후보/발행/형식 필터는 제외. */
    private function ingest(KeywordCategory $cat, array $ranks, string $path): void
    {
        foreach ($ranks as $r) {
            $kw = trim((string) preg_replace('/\s+/u', ' ', $r['keyword']));
            if (! KeywordHubCollector::acceptableKeyword($kw)
                || KeywordSearch::where('origin', 'hub')->where('keyword', $kw)->exists()
                || KeywordCandidate::where('category_id', $cat->id)->where('keyword', $kw)->exists()) {
                $this->skipped++;

                continue;
            }
            KeywordCandidate::create([
                'category_id' => $cat->id,
                'keyword' => $kw,
                'source' => 'datalab',
                'status' => 'pending',
                'note' => "데이터랩 '{$path}' 인기 #{$r['rank']} (최근 30일)",
            ]);
            $this->created++;
        }
    }

    /** naver_cid 기준 카테고리 동기화(없으면 생성). */
    private function ensureCategory(int $cid, string $name, ?int $parentId, int $sort): KeywordCategory
    {
        $cat = KeywordCategory::where('naver_cid', $cid)->first();
        if ($cat) {
            return $cat;
        }

        return KeywordCategory::create([
            'type' => 'shopping',
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => KeywordCategory::makeSlug($name),
            'naver_cid' => $cid,
            'sort' => $sort,
            'is_active' => true,
        ]);
    }
}
