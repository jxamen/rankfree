<?php

namespace App\Console\Commands;

use App\Domain\Keyword\NaverKeywordService;
use App\Models\Keyword;
use App\Models\KeywordCandidate;
use App\Models\KeywordCategory;
use App\Models\KeywordSearch;
use Illuminate\Console\Command;

/**
 * 남은(미조회) 키워드의 네이버 조회수를 확인해, 월 조회수 <=10(의미 없음)은 키워드를 삭제하고
 * >10 만 보존한다. "순서대로 조회수부터 확인 → <=10 제거 → >10만 수집" 파이프라인의 앞단.
 *
 * ⚠️ 이미 발행(인덱싱)된 허브 문서 키워드는 <=10 이라도 삭제하지 않는다(이미 색인됨).
 * 네이버 "< 10"(PC·모바일)은 각 5로 환산되므로 총합 10 이 곧 "수요 없음" 신호다.
 *
 *   php artisan keywords:prune-low-volume --type=place --limit=2000 --sleep=1
 */
class PruneLowVolumeKeywords extends Command
{
    protected $signature = 'keywords:prune-low-volume
        {--type=place : 대상 키워드 타입}
        {--limit=2000 : 이번 실행에서 조회수 확인할 키워드 수}
        {--batch=25 : 조회수 API 한 묶음(내부 5개씩 호출)}
        {--sleep=1 : 묶음 간 대기(초)}';

    protected $description = '남은 키워드 조회수 확인 → 월 <=10(의미없음) 삭제, >10 보존(발행분은 유지).';

    /** 이 값 이하(<=)면 삭제. 네이버 "< 10"(PC·모바일)=총 10. */
    private const MAX_DELETE_VOLUME = 10;

    public function handle(NaverKeywordService $svc): int
    {
        $type = (string) $this->option('type');
        $limit = max(1, (int) $this->option('limit'));
        $batchSize = min(100, max(5, (int) $this->option('batch')));
        $sleep = max(0, (int) $this->option('sleep'));

        $catIds = KeywordCategory::where('type', $type)->pluck('id');
        if ($catIds->isEmpty()) {
            $this->warn("타입 '{$type}' 카테고리가 없습니다.");

            return self::SUCCESS;
        }

        $remaining = Keyword::where('type', $type)->whereNull('volume_checked_at')->count();
        $this->info("미조회 {$type} 키워드 {$remaining}개 · 이번 실행 목표 {$limit}개");

        $processed = 0;
        $deleted = 0;
        $kept = 0;
        $keptPublished = 0;
        $noData = 0;

        while ($processed < $limit) {
            $rows = Keyword::where('type', $type)->whereNull('volume_checked_at')
                ->orderBy('id')->limit(min($batchSize, $limit - $processed))
                ->get(['id', 'keyword']);
            if ($rows->isEmpty()) {
                break;
            }

            $vols = $svc->volumes($rows->pluck('keyword')->all());
            // 이 묶음에서 발행(허브)된 키워드 — 폐기분도 '발행됨'으로 인정(스코프 우회)해 마스터를 지키지 않게
            $published = KeywordSearch::withoutGlobalScope('notRetired')
                ->where('origin', 'hub')
                ->whereIn('keyword', $rows->pluck('keyword'))
                ->pluck('keyword')->flip();

            $now = now();
            foreach ($rows as $k) {
                $v = $vols[$k->keyword] ?? null;
                if ($v === null) {
                    // 조회 실패/무응답 — 삭제·갱신하지 않고 다음 실행에서 재시도(오삭제 방지)
                    $noData++;

                    continue;
                }
                $processed++;
                $total = (int) $v['monthly_total'];
                $vals = ['monthly_total' => $total, 'comp_idx' => $v['comp_idx'] ?? null, 'volume_checked_at' => $now];

                if ($total <= self::MAX_DELETE_VOLUME && ! $published->has($k->keyword)) {
                    // 의미 없는 키워드 삭제 — 마스터 + 후보(같은 타입)
                    Keyword::whereKey($k->id)->delete();
                    KeywordCandidate::where('keyword', $k->keyword)->whereIn('category_id', $catIds)->delete();
                    $deleted++;
                } else {
                    // 보존(조회수 반영) — >10 이거나, <=10 이어도 이미 발행됨
                    Keyword::whereKey($k->id)->update($vals);
                    KeywordCandidate::where('keyword', $k->keyword)->whereIn('category_id', $catIds)->update($vals);
                    if ($published->has($k->keyword) && $total <= self::MAX_DELETE_VOLUME) {
                        $keptPublished++;
                    } else {
                        $kept++;
                    }
                }
            }

            $this->line("  누적 처리 {$processed} · 삭제 {$deleted} · 보존 {$kept} · 발행유지 {$keptPublished} · 무응답 {$noData}");
            if ($sleep > 0) {
                sleep($sleep);
            }
        }

        $this->info("완료 — 처리 {$processed} · 삭제 {$deleted} · 보존 {$kept} · 발행유지 {$keptPublished} · 무응답(재시도대상) {$noData}");

        return self::SUCCESS;
    }
}
