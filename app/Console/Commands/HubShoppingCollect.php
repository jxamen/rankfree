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
 * 데이터랩 1·2·3분류를 그대로 KeywordCategory 트리로 동기화(naver_cid 매핑, parent_id 3계층)하고,
 * 각 분류의 인기검색어를 해당 분류 카테고리의 후보(source=datalab)로 적재한다.
 */
class HubShoppingCollect extends Command
{
    protected $signature = 'hub:shopping-collect
        {--root= : 특정 1분류 cid 만(예: 50000001 패션잡화)}
        {--pages= : 분야당 인기검색어 페이지 수(페이지당 20개 · 최대 25=500위, 기본 config)}
        {--depth=3 : 인기검색어 수집 분류 깊이(2=2분류만, 3=3분류 포함)}
        {--tree-only : 카테고리 트리(1·2·3분류)만 동기화하고 인기검색어는 건너뛴다 — 몇 초면 끝난다}
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

        // ── 1단계: 카테고리 트리 전체 먼저 동기화 ──
        // 분류마다 인기검색어까지 받으면 1차 하나당 수 분이 걸려 화면에 카테고리가 찔끔찔끔 나타난다.
        // 트리(children API)만 먼저 훑어 전 분류를 만들고, 키워드는 2단계에서 채운다.
        $targets = []; // [cid, KeywordCategory, 표시경로]
        foreach ($roots as $i => $root) {
            $rootCat = $this->ensureCategory($root['cid'], $root['name'], null, $i + 1);
            $targets[] = [$root['cid'], $rootCat, $root['name']];

            foreach ($datalab->children($root['cid']) as $j => $sub) { // 2분류
                $subCat = $this->ensureCategory($sub['cid'], $sub['name'], $rootCat->id, $j + 1);
                $targets[] = [$sub['cid'], $subCat, $root['name'].' > '.$sub['name']];

                if ($depth >= 3 && ! $sub['leaf']) {
                    foreach ($datalab->children($sub['cid']) as $k => $third) { // 3분류도 카테고리로(3계층)
                        $thirdCat = $this->ensureCategory($third['cid'], $third['name'], $subCat->id, $k + 1);
                        $targets[] = [$third['cid'], $thirdCat, $root['name'].' > '.$sub['name'].' > '.$third['name']];
                    }
                }
            }
            $this->info("[트리] {$root['name']} — 누적 분류 ".count($targets));
        }
        if ($this->option('tree-only')) {
            $this->info('트리 동기화 완료 — 분류 '.count($targets).'개. (--tree-only: 인기검색어 수집은 건너뜀)');

            return self::SUCCESS;
        }
        $this->info('트리 동기화 완료 — 분류 '.count($targets).'개. 이제 분류별 인기검색어를 수집합니다.');

        // ── 2단계: 분류별 인기검색어 ──
        foreach ($targets as $n => [$cid, $cat, $path]) {
            usleep($delay);
            $this->ingest($cat, $datalab->topKeywords($cid, $pages), $path);
            $cat->forceFill(['collected_at' => now()])->save();
            if (($n + 1) % 50 === 0) {
                $this->info('  키워드 '.($n + 1).'/'.count($targets)." 분류 · 신규 {$this->created}");
            }
        }

        $this->info("완료 — 분류 ".count($targets)." · 신규 후보 {$this->created} · 제외(기존/필터) {$this->skipped}");

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
