<?php

namespace App\Console\Commands;

use App\Domain\Community\CommunityActivitySimulator;
use Illuminate\Console\Command;

/** 커뮤니티 페르소나 자동 활동 1회 실행 — 스케줄러/어드민 버튼 공용. */
class CommunitySimulate extends Command
{
    protected $signature = 'community:simulate {--count= : 이번 실행에서 시도할 활동 수(기본 config)}';

    protected $description = '페르소나가 글/댓글/좋아요를 자동으로 남깁니다.';

    public function handle(CommunityActivitySimulator $simulator): int
    {
        $count = (int) ($this->option('count') ?: config('rankfree.community.tick_actions', 8));
        $r = $simulator->run(max(1, $count));
        $this->info("커뮤니티 활동 완료 — 글 {$r['posts']} · 댓글 {$r['comments']} · 좋아요 {$r['likes']}");

        return self::SUCCESS;
    }
}
