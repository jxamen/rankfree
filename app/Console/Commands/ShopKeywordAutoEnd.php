<?php

namespace App\Console\Commands;

use App\Domain\Shopping\ShopKeywordAdEndService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ShopKeywordAutoEnd extends Command
{
    protected $signature = 'shop-keyword:auto-end {--dry-run : 판정만 출력}';

    protected $description = '광고가 끝난 쇼핑 키워드 분석 수집 종료 (결과 보존)';

    public function handle(ShopKeywordAdEndService $service): int
    {
        $lock = Cache::lock('shop-keyword:auto-end', 3600);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        try {
            $plan = $service->plan();
            $this->line(json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $ended = $this->option('dry-run') ? 0 : $service->finish($plan);
            Log::info('shop-keyword:auto-end', ['dry_run' => (bool) $this->option('dry-run'), 'ended' => $ended, 'decisions' => $plan]);
            $this->info('광고 종료 처리 '.$ended.'건');

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
