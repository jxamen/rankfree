<?php

namespace App\Console\Commands;

use App\Domain\Keyword\KeywordHubCollector;
use App\Models\KeywordCategory;
use Illuminate\Console\Command;

/** 키워드 콘텐츠 허브 — 후보 수집. 카테고리를 collected_at 오래된 순으로 로테이션. */
class HubCollect extends Command
{
    protected $signature = 'hub:collect
        {--category= : 특정 카테고리 ID만 수집}
        {--limit= : 이번 실행에서 처리할 카테고리 수(기본 config rankfree.hub.collect_categories)}';

    protected $description = '키워드 허브 — 카테고리 시드에서 연관·자동완성 키워드 후보 수집(22)';

    public function handle(KeywordHubCollector $collector): int
    {
        $cats = $this->option('category')
            ? KeywordCategory::whereKey((int) $this->option('category'))->get()
            // 로테이션은 수동(시드) 카테고리만 — 데이터랩 분류(naver_cid)는 시드가 없어
            // 로테이션 슬롯만 소모한다(그쪽은 hub:shopping-collect 가 수집).
            : KeywordCategory::where('is_active', true)->whereNull('naver_cid')
                ->orderByRaw('collected_at is null desc')->orderBy('collected_at')
                ->limit((int) ($this->option('limit') ?: config('rankfree.hub.collect_categories', 3)))
                ->get();

        if ($cats->isEmpty()) {
            $this->info('수집할 카테고리가 없습니다.');

            return self::SUCCESS;
        }

        foreach ($cats as $cat) {
            $s = $collector->collect($cat);
            $this->info(sprintf('[%s] 시드 %d · 신규 %d · 갱신 %d · 필터 %d',
                $cat->name, $s['seeds'], $s['created'], $s['updated'], $s['filtered']));
        }

        return self::SUCCESS;
    }
}
