<?php

namespace App\Console\Commands;

use App\Domain\Shopping\ShopAnalysisRankRegistration;
use App\Models\ShopKeywordAnalysis;
use Illuminate\Console\Command;

class ShopAnalysisRankSync extends Command
{
    protected $signature = 'shop-keyword:rank-sync';
    protected $description = '신규 부스팅샵 분석의 쇼핑 순위체크 등록 및 실패 재시도';

    public function handle(ShopAnalysisRankRegistration $registration): int
    {
        // Historical requests without the explicit opt-in marker are never backfilled.
        ShopKeywordAnalysis::whereNotNull('product_collection->_boost->source_order')
            ->chunkById(100, function ($analyses) use ($registration) {
                foreach ($analyses as $analysis) {
                    $last = $analysis->boostMeta()['rank_registration'] ?? [];
                    if (!empty($last['at']) && empty($last['failed']) &&
                        in_array($analysis->status, ['done', 'target_reached', 'ad_ended']) &&
                        !$analysis->combos()->where('checked_at', '>', \Carbon\Carbon::parse($last['at']))->exists()) {
                        continue;
                    }
                    $registration->safelySync($analysis);
                }
            });
        return self::SUCCESS;
    }
}
