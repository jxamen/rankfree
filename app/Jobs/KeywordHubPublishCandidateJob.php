<?php

namespace App\Jobs;

use App\Domain\Keyword\HubAutoRun;
use App\Domain\Keyword\KeywordHubPublisher;
use App\Domain\Seo\SearchEnginePing;
use App\Models\KeywordCandidate;
use App\Models\KeywordSearch;
use App\Models\MarketAnalysis;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class KeywordHubPublishCandidateJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public int $uniqueFor = 1800;

    public function __construct(public int $candidateId)
    {
        $this->onQueue('hub-publish');
    }

    public function uniqueId(): string
    {
        return (string) $this->candidateId;
    }

    public function handle(KeywordHubPublisher $publisher, SearchEnginePing $ping): void
    {
        if (! HubAutoRun::isRunning()) {
            return;
        }

        $candidate = KeywordCandidate::with('category')->find($this->candidateId);
        if (! $candidate || ! in_array($candidate->status, ['pending', 'approved'], true)) {
            return;
        }

        $type = HubAutoRun::state()['type'] ?? null;
        if ($type && $candidate->category?->type !== $type) {
            return;
        }

        $doc = $publisher->publish($candidate);
        HubAutoRun::progress($doc ? 1 : 0, $doc ? 0 : 1, $candidate->category?->type);

        if ($doc instanceof KeywordSearch || $doc instanceof MarketAnalysis) {
            $ping->afterHubPublish(collect([$doc]));
        }
    }
}
