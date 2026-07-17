<?php

namespace App\Console\Commands;

use App\Domain\Keyword\HubAutoRun;
use App\Domain\Keyword\KeywordHubPublisher;
use App\Domain\Seo\SearchEnginePing;
use Illuminate\Console\Command;

/**
 * 키워드 허브 자동 발행 — 관리자 토글(HubAutoRun)이 켜져 있을 때만,
 * 쌓인 후보(pending·approved)를 유형별로 검색량 큰 순 배치 분석·발행한다(매분 크론).
 * 브라우저와 무관하게 서버가 계속 드레인하고, 다 비면 스스로 멈춘다.
 */
class HubAutoPublish extends Command
{
    protected $signature = 'hub:auto-publish
        {--limit= : 이번 실행 발행 상한(기본 config rankfree.hub.auto_per_run)}
        {--seconds= : 이번 실행 시간 예산(초, 기본 45 — withoutOverlapping 과 함께 1분 넘김 방지)}';

    protected $description = '키워드 허브 — 자동 발행이 켜져 있으면 쌓인 후보를 유형별로 배치 분석·발행(22)';

    public function handle(KeywordHubPublisher $publisher): int
    {
        if (! HubAutoRun::isRunning()) {
            return self::SUCCESS;   // 꺼져 있음 — 즉시 no-op
        }

        $limit = (int) ($this->option('limit') ?: config('rankfree.hub.auto_per_run', 15));
        $budget = (int) ($this->option('seconds') ?: 45);
        $deadline = microtime(true) + max(5, $budget);
        $type = HubAutoRun::state()['type'] ?? null;

        $ok = $hold = 0;
        $published = collect();
        for ($i = 0; $i < $limit; $i++) {
            if (microtime(true) >= $deadline) {
                break;                       // 시간 예산 소진 — 다음 분 크론이 이어서
            }
            if (! HubAutoRun::isRunning()) {
                break;                       // 관리자가 중간에 껐다 → 즉시 멈춤
            }
            $c = HubAutoRun::query($type)
                ->orderByRaw('monthly_total is null')->orderByDesc('monthly_total')->orderBy('id')
                ->first();
            if (! $c) {
                break;                       // 다 드레인
            }
            if ($i > 0) {
                usleep(300_000);             // 외부 API 부담 완화
            }
            $doc = $publisher->publish($c);
            if ($doc) {
                $ok++;
                $published->push($doc);
            } else {
                $hold++;
            }
        }

        HubAutoRun::progress($ok, $hold);
        if ($note = app(SearchEnginePing::class)->afterHubPublish($published)) {
            $this->line($note);
        }
        $this->info("자동 발행 — 발행 {$ok} · 보류 {$hold}");

        return self::SUCCESS;
    }
}
