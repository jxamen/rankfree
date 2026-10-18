<?php

namespace App\Domain\Shopping;

use App\Jobs\ShopKeywordCheckJob;
use App\Models\ShopKeywordAnalysis;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ShopKeywordAdEndService
{
    public function __construct(private BoostingShopCampaignReader $reader, private ShopKeywordAdEndPolicy $policy) {}

    public function plan(?CarbonImmutable $now = null): array
    {
        // Failure to read Boosting Shop must NEVER be interpreted as an empty campaign set.
        $index = $this->policy->index($this->reader->shopping());
        $now ??= CarbonImmutable::now('Asia/Seoul');

        return ShopKeywordAnalysis::whereIn('status', ShopKeywordAdEndPolicy::ACTIVE_STATUSES)->orderBy('id')->get()
            ->map(function ($a) use ($index, $now) {
                return $this->policy->decide($this->analysisData($a), $index, $now);
            })->all();
    }

    public function finish(array $plan): int
    {
        $index = $this->policy->index($this->reader->shopping());
        $count = 0;
        foreach ($plan as $decision) {
            if (! $decision['reason']) {
                continue;
            }
            $count += DB::transaction(function () use ($decision, $index) {
                $a = ShopKeywordAnalysis::whereKey($decision['id'])->lockForUpdate()->first();
                if (! $a || ! in_array($a->status, ShopKeywordAdEndPolicy::ACTIVE_STATUSES, true)) {
                    return 0;
                }
                $freshDecision = $this->policy->decide($this->analysisData($a), $index, CarbonImmutable::now('Asia/Seoul'));
                if (! $freshDecision['reason']) {
                    return 0;
                }
                $a->update(['status' => 'ad_ended']);

                // Persistent jobs may already be reserved; the job and all collection entrypoints
                // also enforce this terminal status before doing work or dispatching another batch.
                if (config('queue.default') === 'database') {
                    $table = config('queue.connections.database.table', 'jobs');
                    foreach (DB::table($table)->where('payload', 'like', '%ShopKeywordCheckJob%')->get(['id', 'payload']) as $job) {
                        $payload = json_decode($job->payload, true);
                        $command = $payload['data']['command'] ?? '';
                        if (($payload['displayName'] ?? '') === ShopKeywordCheckJob::class
                            && preg_match('/s:10:"analysisId";i:'.(int) $a->id.';/', $command)) {
                            DB::table($table)->where('id', $job->id)->delete();
                        }
                    }
                }

                return 1;
            });
        }

        return $count;
    }

    private function analysisData(ShopKeywordAnalysis $a): array
    {
        $data = $a->getAttributes();
        $times = array_filter([$a->collection_seen_at?->toDateTimeString(), $a->items()->max('checked_at'),
            DB::table('shop_product_infos')->where('user_id', $a->user_id)
                ->where('channel_product_id', $a->product_id)->max('collected_at')]);
        $data['last_collected_at'] = $times ? max($times) : null;

        return $data;
    }
}
