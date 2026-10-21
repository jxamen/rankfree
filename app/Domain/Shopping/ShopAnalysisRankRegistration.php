<?php

namespace App\Domain\Shopping;

use App\Models\ShopKeywordAnalysis;
use Illuminate\Support\Facades\DB;

/** Analysis registration never performs a search or sends an external notification. */
class ShopAnalysisRankRegistration
{
    public function sync(ShopKeywordAnalysis $analysis): array
    {
        return DB::transaction(function () use ($analysis) {
            $a = ShopKeywordAnalysis::whereKey($analysis->id)->lockForUpdate()->firstOrFail();
            $meta = $a->boostMeta();
            if (empty($meta['source_order'])) {
                return [];
            }
            // The authenticated API owner is the existing agency mapping. Never match
            // customer accounts by an unverified display name or caller-supplied user id.
            $owner = $a->user()->lockForUpdate()->firstOrFail();
            $keywords = collect([$a->core_keyword])->merge(
                $a->combos()->whereBetween('rank', [1, 5])->pluck('keyword')
            )->map(fn ($k) => trim((string) $k))->filter()->unique()->values();
            $result = ['registered' => 0, 'skipped' => 0, 'failed' => 0, 'at' => now()->toIso8601String()];
            foreach ($keywords as $keyword) {
                try {
                    // Nested transaction isolates a failed registration from the order.
                    $r = DB::transaction(fn () => app(ShopRankSlotService::class)->ensureTracked(
                        $owner, $a->product_url, $keyword, '부스팅샵 '.$meta['source_order']
                    ));
                    $result[$r['status'] === 'exists' ? 'skipped' : 'registered']++;
                } catch (\Throwable) {
                    $result['failed']++;
                }
            }
            $meta['rank_registration'] = $result;
            $a->setBoostMeta($meta);
            $a->saveQuietly();
            return $result;
        });
    }

    public function safelySync(ShopKeywordAnalysis $analysis): array
    {
        try {
            return $this->sync($analysis);
        } catch (\Throwable) {
            // The source marker remains persisted; the scheduled retry will pick it up.
            return ['registered' => 0, 'skipped' => 0, 'failed' => 1, 'pending' => true, 'at' => now()->toIso8601String()];
        }
    }
}
