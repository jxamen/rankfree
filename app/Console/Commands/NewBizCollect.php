<?php

namespace App\Console\Commands;

use App\Domain\NewBiz\NewBusinessCollector;
use App\Domain\NewBiz\NewBusinessPlaceMatcher;
use App\Models\NewBusiness;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * 신규 개업(인허가) 수집 — 인허가일자 기준 최근 N일치를 업종별로 받아 적재하고,
 * **같은 실행에서 네이버 플레이스 등록 여부까지 확인한다**(수집과 매칭은 한 흐름 — 따로 돌리지 않는다).
 */
class NewBizCollect extends Command
{
    protected $signature = 'newbiz:collect
        {--date= : 특정 인허가일자만(YYYY-MM-DD)}
        {--days= : 최근 N일(기본 config rankfree.newbiz.collect_days)}
        {--svc= : 특정 서비스만(예: LOCALDATA_072404)}
        {--no-place : 플레이스 확인 없이 수집만}';

    protected $description = '신규 개업 — 인허가 공공데이터 수집 + 네이버 플레이스 등록 여부 확인(24, 관리자 열람용)';

    public function handle(NewBusinessCollector $collector, NewBusinessPlaceMatcher $matcher): int
    {
        $services = (array) config('rankfree.newbiz.services', []);
        if ($only = $this->option('svc')) {
            $services = array_intersect_key($services, [$only => true]);
            if (! $services) {
                $this->error("알 수 없는 서비스: {$only}");

                return self::FAILURE;
            }
        }

        $dates = $this->option('date')
            ? [Carbon::parse($this->option('date'))]
            : collect(range(0, max(1, (int) ($this->option('days') ?: config('rankfree.newbiz.collect_days', 7))) - 1))
                // 공공데이터는 D-2 기준 현행화 — 오늘·어제는 아직 비어 있는 게 정상
                ->map(fn ($i) => now()->subDays($i + 2))->all();

        $created = $updated = 0;
        foreach ($services as $svc => $label) {
            foreach ($dates as $d) {
                $r = $collector->collectDate($svc, $label, $d);
                if ($r['error']) {
                    $this->warn("[{$label}] {$d->toDateString()} 오류: {$r['error']}");

                    continue;
                }
                $created += $r['created'];
                $updated += $r['updated'];
                $this->line(sprintf('  [%s] %s — 원천 %d건 · 신규 %d · 갱신 %d', $label, $d->toDateString(), $r['total'], $r['created'], $r['updated']));
            }
        }

        $this->info("수집 완료 — 신규 {$created} · 갱신 {$updated}");

        // 같은 실행에서 플레이스 확인 — 방금 담은 건 + 재확인할 때가 된 미등록(상한 없이 전부)
        if (! $this->option('no-place')) {
            $rows = NewBusiness::open()->needsPlaceCheck()
                ->orderByDesc('apv_perm_ymd')->orderByDesc('id')->get();
            if ($rows->isNotEmpty()) {
                $this->line('  플레이스 확인 '.$rows->count().'건…');
                $bar = $this->output->createProgressBar($rows->count());
                $bar->start();
                $stat = ['found' => 0, 'not_found' => 0];
                foreach ($rows as $i => $biz) {
                    if ($i > 0) {
                        usleep(300_000);   // 공식 지역검색 API 호출 간격
                    }
                    $stat[$matcher->match($biz)]++;
                    $bar->advance();
                }
                $bar->finish();
                $this->newLine();
                $this->info("플레이스 확인 완료 — 있음 {$stat['found']} · 없음(미등록) {$stat['not_found']}");
            }
        }
        if (app(\App\Domain\NewBiz\SeoulLocalDataClient::class)->isSampleKey()) {
            $this->warn('⚠️ 인증키가 sample 이라 하루당 5건만 받습니다. 관리자 > 환경 설정 > 연동 > 서울 열린데이터광장 에 인증키를 넣으세요(data.seoul.go.kr 마이페이지에서 즉시 발급).');
        }

        return self::SUCCESS;
    }
}
