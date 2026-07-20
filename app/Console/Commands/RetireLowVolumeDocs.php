<?php

namespace App\Console\Commands;

use App\Domain\Seo\SearchEnginePing;
use App\Models\KeywordCategory;
use App\Models\KeywordSearch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * 저품질(월 조회수 <=N) 발행 문서를 '폐기' 표시한다 — 인덱싱 안전하게 정리.
 *  · 페이지: 카테고리 허브로 301 리다이렉트(KeywordAnalysisController@shared)
 *  · 사이트맵: 폐기 문서 제외(SitemapController)
 *  · 추천: RelatedDocsService 에서 제외
 *  · 재크롤: IndexNow(네이버·빙) 로 폐기 URL 제출 + 사이트맵 캐시 갱신 + 구글 사이트맵 재제출
 * 하드 삭제가 아니라 소프트 폐기라 되돌릴 수 있고, 301 로 죽은 링크(404 더미)를 만들지 않는다.
 *
 *   php artisan keywords:retire-low-volume-docs --type=place --max-volume=10 --dry-run
 */
class RetireLowVolumeDocs extends Command
{
    protected $signature = 'keywords:retire-low-volume-docs
        {--type=place : 대상 카테고리 타입}
        {--max-volume=10 : 월 조회수가 이 값 이하인 발행 문서를 폐기}
        {--limit=30000 : 이번 실행 최대 폐기 수}
        {--dry-run : 표시만(변경 안 함)}';

    protected $description = '저품질(월 조회수<=N) 발행 문서 폐기 → 301 리다이렉트·사이트맵 제외·IndexNow 재크롤.';

    public function handle(SearchEnginePing $ping): int
    {
        $type = (string) $this->option('type');
        $maxVol = (int) $this->option('max-volume');
        $limit = max(0, (int) $this->option('limit'));
        $dry = (bool) $this->option('dry-run');

        $catIds = KeywordCategory::where('type', $type)->pluck('id');
        $base = fn () => KeywordSearch::where('origin', 'hub')
            ->whereNull('retired_at')
            ->whereIn('category_id', $catIds)
            ->where('monthly_total', '<=', $maxVol);

        $total = $base()->count();
        $this->info("폐기 대상 {$type} 문서(월 조회수 <= {$maxVol}): {$total}개".($dry ? '  [dry-run]' : ''));
        if ($total === 0 || $limit === 0) {
            return self::SUCCESS;
        }

        $docs = $base()->orderBy('id')->limit($limit)->get(['id', 'slug', 'keyword']);
        if ($dry) {
            $this->line('  예시: '.$docs->take(8)->pluck('keyword')->implode(', '));

            return self::SUCCESS;
        }

        // 폐기 표시(청크) + 폐기 URL 수집
        $now = now();
        $urls = [];
        foreach ($docs->chunk(1000) as $chunk) {
            KeywordSearch::whereIn('id', $chunk->pluck('id'))->update(['retired_at' => $now]);
            foreach ($chunk as $d) {
                if ($d->slug) {
                    $urls[] = url('/keyword/'.rawurlencode($d->slug));
                }
            }
        }
        $this->info('폐기 표시 '.$docs->count().'개 완료 → 사이트맵 갱신·재크롤 알림');

        // 사이트맵 캐시 버전 올림(다음 요청부터 폐기 문서 빠짐)
        Cache::forever(\App\Console\Commands\SitemapRefresh::VERSION_KEY, \App\Console\Commands\SitemapRefresh::version() + 1);

        // IndexNow(네이버·빙) — 폐기 URL 재크롤 유도(→ 301 인지). 1,000개씩 제출.
        $pinged = 0;
        foreach (array_chunk($urls, 1000) as $batch) {
            $r = $ping->pingIndexNow($batch);
            if (! empty($r['ok'])) {
                $pinged += count($batch);
            }
        }
        // 구글엔 사이트맵 재제출(폐기 반영). 실패해도 무해.
        $gsc = $ping->submitSitemapToGoogle();

        $this->info("완료 — 폐기 {$docs->count()} · IndexNow {$pinged}건 · 구글 사이트맵: {$gsc['message']} · 남은 대상 ".max(0, $total - $docs->count()));

        return self::SUCCESS;
    }
}
