<?php

namespace App\Jobs\Concerns;

use App\Models\KeywordHubRunItem;
use Illuminate\Support\Str;
use Throwable;

trait TracksKeywordHubRunItem
{
    protected function markHubItemRunning(KeywordHubRunItem $item): void
    {
        $item->forceFill([
            'status' => 'running',
            'started_at' => $item->started_at ?? now(),
            'error' => null,
        ])->save();

        $run = $item->run()->first();
        if ($run && $run->status !== 'cancelled') {
            $run->forceFill([
                'status' => 'running',
                'started_at' => $run->started_at ?? now(),
                'finished_at' => null,
            ])->save();
        }
    }

    protected function completeHubItem(KeywordHubRunItem $item, array $stats = [], ?string $note = null): void
    {
        $item->forceFill([
            'status' => 'completed',
            'seeds' => (int) ($stats['seeds'] ?? $item->seeds),
            'created_candidates' => (int) ($stats['created'] ?? $stats['created_candidates'] ?? $item->created_candidates),
            'updated_candidates' => (int) ($stats['updated'] ?? $stats['updated_candidates'] ?? $item->updated_candidates),
            'filtered_candidates' => (int) ($stats['filtered'] ?? $stats['filtered_candidates'] ?? $item->filtered_candidates),
            'note' => $note !== null ? Str::limit($note, 500, '') : $item->note,
            'error' => null,
            'finished_at' => now(),
        ])->save();

        $item->run()->first()?->refreshSummary();
    }

    protected function failHubItem(int $itemId, ?Throwable $exception): void
    {
        $item = KeywordHubRunItem::find($itemId);
        if (! $item || $item->status === 'completed') {
            return;
        }

        $item->forceFill([
            'status' => 'failed',
            'error' => Str::limit($exception?->getMessage() ?? 'Unknown error', 2000, ''),
            'finished_at' => now(),
        ])->save();

        $item->run()->first()?->refreshSummary();
    }
}
