<?php

namespace App\Console\Commands;

use App\Domain\Keyword\KeywordHubPublisher;
use App\Domain\Seo\SearchEnginePing;
use App\Models\KeywordCandidate;
use Illuminate\Console\Command;

/** 키워드 콘텐츠 허브 — 승인 후보 발행. 검색량 큰 순, 1회 상한(쿼터·도어웨이 방지). */
class HubPublish extends Command
{
    protected $signature = 'hub:publish
        {--limit= : 이번 실행 발행 상한(기본 config rankfree.hub.publish_per_run)}';

    protected $description = '키워드 허브 — 승인된 후보를 분석해 공개 문서(/keyword/슬러그)로 발행(22)';

    public function handle(KeywordHubPublisher $publisher): int
    {
        $limit = (int) ($this->option('limit') ?: config('rankfree.hub.publish_per_run', 10));
        $cands = KeywordCandidate::where('status', 'approved')
            ->orderByRaw('monthly_total is null')->orderByDesc('monthly_total')->orderBy('id')
            ->limit($limit)->get();

        if ($cands->isEmpty()) {
            $this->info('발행할 승인 후보가 없습니다.');

            return self::SUCCESS;
        }

        $ok = $hold = 0;
        $published = collect();
        foreach ($cands as $i => $c) {
            if ($i > 0) {
                usleep(300_000); // 연속 수집 간 간격 — 외부 API 부담 완화
            }
            $doc = $publisher->publish($c);
            if ($doc) {
                $ok++;
                $published->push($doc);
                $this->info("발행: {$c->keyword} → ".$doc->shareUrl());
            } else {
                $hold++;
                $this->warn("보류(데이터 부족): {$c->keyword}");
            }
        }
        $this->info("완료 — 발행 {$ok} · 보류 {$hold}");

        if ($note = app(SearchEnginePing::class)->afterHubPublish($published)) {
            $this->line($note);
        }

        return self::SUCCESS;
    }
}
