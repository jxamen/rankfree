<?php

namespace App\Jobs;

use App\Domain\Keyword\KeywordHubCollectionControl;
use App\Jobs\Concerns\TracksKeywordHubRunItem;
use App\Models\KeywordCandidate;
use App\Models\KeywordCategory;
use App\Models\KeywordHubRunItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class KeywordHubCollectShoppingRootJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TracksKeywordHubRunItem;

    public int $tries = 1000;

    public int $timeout = 1800;

    public function __construct(public int $itemId)
    {
        $this->onQueue('hub-shopping');
    }

    public function handle(): void
    {
        $item = KeywordHubRunItem::with('run')->find($this->itemId);
        if (! $item || $item->run?->status === 'cancelled') {
            return;
        }

        if (! KeywordHubCollectionControl::enabled()) {
            $item->forceFill([
                'status' => 'queued',
                'note' => '관리자 OFF 상태로 대기 중',
            ])->save();
            $this->release(60);

            return;
        }

        $this->markHubItemRunning($item);

        $rootCid = $item->target_type === 'shopping_root' ? (int) $item->target_id : null;
        $lockName = $rootCid ? "hub:shopping-root:{$rootCid}" : 'hub:shopping-root:all';
        $lock = Cache::lock($lockName, 3600);

        if (! $lock->get()) {
            $this->completeHubItem($item, [], '같은 쇼핑 분류 수집이 이미 실행 중이라 건너뜀');

            return;
        }

        try {
            $before = $this->candidateCount($rootCid);
            $options = $item->run?->options ?? [];
            $args = [
                '--pages' => (int) ($options['shopping_pages'] ?? config('rankfree.hub.datalab_pages', 25)),
                '--depth' => (int) ($options['shopping_depth'] ?? 3),
                '--delay-ms' => (int) ($options['shopping_delay_ms'] ?? 300),
            ];
            if ($rootCid) {
                $args['--root'] = $rootCid;
            }

            $code = Artisan::call('hub:shopping-collect', $args);
            $output = trim(Artisan::output());
            if ($code !== 0) {
                throw new RuntimeException($output !== '' ? $output : 'hub:shopping-collect failed');
            }

            $after = $this->candidateCount($rootCid);
            $created = max(0, $after - $before);
            $this->completeHubItem($item, ['created' => $created], $this->lastOutputLine($output));
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->failHubItem($this->itemId, $exception);
    }

    private function candidateCount(?int $rootCid): int
    {
        if (! $rootCid) {
            return KeywordCandidate::whereHas('category', fn ($q) => $q->whereNotNull('naver_cid'))->count();
        }

        $ids = $this->categoryIdsForRoot($rootCid);
        if (! $ids) {
            return 0;
        }

        return KeywordCandidate::whereIn('category_id', $ids)->count();
    }

    /** @return array<int> */
    private function categoryIdsForRoot(int $rootCid): array
    {
        $root = KeywordCategory::where('naver_cid', $rootCid)->first();
        if (! $root) {
            return [];
        }

        $ids = [$root->id];
        $frontier = [$root->id];

        while ($frontier) {
            $children = KeywordCategory::whereIn('parent_id', $frontier)->pluck('id')->all();
            if (! $children) {
                break;
            }
            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function lastOutputLine(string $output): ?string
    {
        if ($output === '') {
            return null;
        }

        $lines = preg_split('/\R/u', $output) ?: [];

        return trim((string) end($lines));
    }
}
