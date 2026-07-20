<?php

namespace App\Console\Commands;

use App\Domain\Place\PlaceRankChecker;
use App\Domain\Place\PlaceSerpStore;
use App\Models\KeywordSearch;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 플레이스 허브 문서 키워드의 SERP를 재수집해 업체 좌표(x/y)를 적재한다(지리 "주변 추천" 기반).
 * nCaptcha 토큰 필요 — 서버 크론이 채워둔 토큰을 쓴다(로컬에선 blocked).
 *
 *   php artisan place:backfill-coords --limit=200 --days=30 --sleep=4 --top=50
 */
class BackfillPlaceCoords extends Command
{
    protected $signature = 'place:backfill-coords
        {--limit=200 : 이번 실행에서 수집할 키워드 수}
        {--days=30 : 이 기간 안에 좌표까지 수집된 키워드는 건너뜀}
        {--sleep=4 : 키워드 간 대기(초) — 네이버 차단 완화}
        {--top=50 : 키워드당 상위 업체 수}';

    protected $description = '플레이스 키워드 SERP 재수집으로 업체 좌표(x/y)를 적재(지리 추천용).';

    /** 월 조회수가 이 값 이하(<=10)면 수집하지 않는다 — 네이버 "< 10"(PC·모바일)=총 10, 검색 수요 없음. */
    private const MAX_SKIP_VOLUME = 10;

    public function handle(PlaceRankChecker $checker, PlaceSerpStore $store): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $days = max(0, (int) $this->option('days'));
        $sleep = max(1, (int) $this->option('sleep'));
        $top = min(100, max(10, (int) $this->option('top')));

        // 대상: 플레이스 타입 허브 문서 키워드(검색량 큰 순). 스킵을 감안해 넉넉히 받는다.
        // ★ 월 조회수 <=10 은 좌표 분석 대상에서 제외(=10 은 네이버 "< 10" 신호, 의미 없음).
        //   단, 이미 발행(인덱싱)된 문서는 삭제하지 않는다 — 여기선 '수집 안 함'일 뿐.
        $keywords = KeywordSearch::query()
            ->where('keyword_searches.origin', 'hub')
            ->join('keyword_categories as kc', 'kc.id', '=', 'keyword_searches.category_id')
            ->where('kc.type', 'place')
            ->where('keyword_searches.monthly_total', '>', self::MAX_SKIP_VOLUME)
            ->orderByDesc('keyword_searches.monthly_total')
            ->limit($limit * 4)
            ->pluck('keyword_searches.keyword')
            ->unique()->values();

        if ($keywords->isEmpty()) {
            $this->warn('플레이스 허브 문서 키워드가 없습니다.');

            return self::SUCCESS;
        }

        $done = 0;
        $skipped = 0;
        $failed = 0;
        $blockStreak = 0;
        $this->info("대상 후보 {$keywords->count()}개 · 목표 {$limit}개 수집 시작");

        foreach ($keywords as $kw) {
            if ($done >= $limit) {
                break;
            }
            if ($this->alreadyHasCoords($kw, $days)) {
                $skipped++;

                continue;
            }

            $cat = $this->catFor($kw);
            $r = $checker->serpFetch($kw, $cat, null, $top);

            if (! empty($r['blocked'])) {
                $blockStreak++;
                $this->warn("  [차단] {$kw} — 연속 {$blockStreak}회");
                // nCaptcha 토큰이 마르면 계속 막힌다 — 5회 연속이면 중단(토큰 갱신 후 재실행)
                if ($blockStreak >= 5) {
                    $this->error('연속 차단 5회 — nCaptcha 토큰 소진으로 보고 중단합니다. 토큰 갱신 후 다시 실행하세요.');
                    break;
                }
                sleep($sleep * 2);

                continue;
            }
            $blockStreak = 0;

            $items = (array) ($r['items'] ?? []);
            if (! $items) {
                $failed++;
                $this->line("  [빈결과] {$kw}");

                continue;
            }

            $store->save($kw, $cat, $items);
            $withXy = collect($items)->filter(fn ($i) => ! empty($i['x']))->count();
            $done++;
            $this->line("  [{$done}/{$limit}] {$kw} ({$cat}) — 업체 ".count($items)."개 · 좌표 {$withXy}개");

            sleep($sleep);
        }

        $this->info("완료 — 수집 {$done} · 스킵 {$skipped} · 실패 {$failed}");

        return self::SUCCESS;
    }

    /** 최근 days 내에 이미 '좌표까지' 수집된 키워드인가. */
    private function alreadyHasCoords(string $keyword, int $days): bool
    {
        if ($days <= 0) {
            return false;
        }
        $lastAt = DB::table('keyword_place_ranks')->where('keyword', $keyword)->max('collected_at');
        if (! $lastAt || Carbon::parse($lastAt)->lt(now()->subDays($days))) {
            return false;
        }

        return DB::table('keyword_place_ranks as r')
            ->join('place_businesses as b', 'b.place_id', '=', 'r.place_id')
            ->where('r.keyword', $keyword)->whereNotNull('b.x')->exists();
    }

    /** 허브 업종 카테고리명 → pcmap 업종 키(KeywordBrowseController 와 동일 규칙). */
    private function catFor(string $keyword): string
    {
        $name = DB::table('keyword_candidates as c')
            ->join('keyword_categories as k', 'k.id', '=', 'c.category_id')
            ->where('c.keyword', $keyword)->value('k.name');

        return match ($name) {
            '맛집·음식점' => 'restaurant',
            '병원·의원' => 'hospital',
            '헤어샵' => 'hairshop',
            '네일·뷰티' => 'nailshop',
            '숙박·여행' => 'accommodation',
            default => 'place',
        };
    }
}
