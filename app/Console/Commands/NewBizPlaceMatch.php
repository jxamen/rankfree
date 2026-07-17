<?php

namespace App\Console\Commands;

use App\Domain\NewBiz\NewBusinessPlaceMatcher;
use App\Models\NewBusiness;
use Illuminate\Console\Command;

/**
 * 신규 개업 → 네이버 플레이스 매칭(24 2단계).
 * **상한 없이 대상 전부**를 처리한다 — 미확인(pending) + 재확인할 때가 된 미등록(scopeNeedsPlaceCheck).
 * 미등록도 며칠 뒤 플레이스가 새로 열릴 수 있으므로 recheck_after_days 마다 자동으로 다시 찾는다.
 */
class NewBizPlaceMatch extends Command
{
    protected $signature = 'newbiz:place-match
        {--limit= : 이번 실행만 건수를 제한(기본: 제한 없음 — 대상 전부)}
        {--recheck : 확인 주기와 무관하게 영업 중 전 업소를 다시 조회}';

    protected $description = '신규 개업 — 업소의 네이버 플레이스 등록 여부 확인·링크 연결(24)';

    public function handle(NewBusinessPlaceMatcher $matcher): int
    {
        $rows = NewBusiness::open()
            ->when(! $this->option('recheck'), fn ($q) => $q->needsPlaceCheck())
            ->orderByDesc('apv_perm_ymd')->orderByDesc('id')
            ->when($this->option('limit'), fn ($q) => $q->limit((int) $this->option('limit')))
            ->get();

        if ($rows->isEmpty()) {
            $this->info('확인할 신규 개업 업소가 없습니다.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($rows->count());
        $bar->start();
        $stat = ['found' => 0, 'not_found' => 0];
        foreach ($rows as $i => $biz) {
            if ($i > 0) {
                usleep(300_000);   // 공식 API 호출 간격
            }
            $stat[$matcher->match($biz)]++;
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $this->info("매칭 완료 — {$rows->count()}건 · 플레이스 있음 {$stat['found']} · 없음(미등록) {$stat['not_found']}");
        if (! config('rankfree.shopping.api_keys')) {
            $this->warn('⚠️ 네이버 개발자센터 키(NAVER_SHOPPING_API_KEYS)가 없어 지역검색이 동작하지 않습니다 — 전부 미등록으로 나옵니다.');
        }

        return self::SUCCESS;
    }
}
