<?php

namespace App\Console\Commands;

use App\Domain\Keyword\HubAutoRun;
use App\Jobs\KeywordHubPublishCandidateJob;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class HubAutoPublish extends Command
{
    protected $signature = 'hub:auto-publish
        {--limit= : Number of candidates to queue this run}
        {--seconds= : Kept for backward compatibility; jobs now run in queue workers}';

    protected $description = 'Queue keyword auto analysis jobs when the admin toggle is enabled';

    public function handle(): int
    {
        if (! HubAutoRun::isRunning()) {
            return self::SUCCESS;
        }

        $limit = max(1, (int) ($this->option('limit') ?: config('rankfree.hub.auto_per_run', 15)));
        $type = HubAutoRun::state()['type'] ?? null;

        $candidates = $this->candidates($type, $limit);

        foreach ($candidates as $candidate) {
            KeywordHubPublishCandidateJob::dispatch($candidate->id);
        }

        $this->info('Queued keyword hub publish jobs: '.$candidates->count());

        return self::SUCCESS;
    }

    private function candidates(?string $type, int $limit): Collection
    {
        if ($type) {
            return $this->candidateQuery($type)->limit($limit)->get();
        }

        $perType = max(1, (int) floor($limit / 2));
        $picked = collect(['shopping', 'place'])
            ->flatMap(fn (string $type) => $this->candidateQuery($type)->limit($perType)->get())
            ->unique('id')
            ->values();

        if ($picked->count() >= $limit) {
            return $picked->take($limit)->values();
        }

        $extra = $this->candidateQuery(null)
            ->when($picked->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $picked->pluck('id')->all()))
            ->limit($limit - $picked->count())
            ->get();

        return $picked->concat($extra)->unique('id')->take($limit)->values();
    }

    private function candidateQuery(?string $type)
    {
        return HubAutoRun::query($type)
            ->orderByRaw('monthly_total is null')
            ->orderByDesc('monthly_total')
            ->orderBy('id');
    }
}
