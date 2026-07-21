<?php

namespace App\Jobs;

use App\Domain\Keyword\KeywordHubCollector;
use App\Domain\Keyword\KeywordHubCollectionControl;
use App\Jobs\Concerns\TracksKeywordHubRunItem;
use App\Models\KeywordCategory;
use App\Models\KeywordHubRunItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

class KeywordHubCollectCategoryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TracksKeywordHubRunItem;

    public int $tries = 1000;

    public int $timeout = 300;

    public function __construct(public int $itemId)
    {
        $this->onQueue('hub-place');
    }

    public function handle(KeywordHubCollector $collector): void
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

        $categoryId = (int) $item->target_id;
        $lock = Cache::lock("hub:collect:category:{$categoryId}", 600);

        if (! $lock->get()) {
            $this->completeHubItem($item, [], '같은 카테고리 수집이 이미 실행 중이라 건너뜀');

            return;
        }

        try {
            $category = KeywordCategory::find($categoryId);
            if (! $category) {
                $this->completeHubItem($item, [], '카테고리를 찾을 수 없어 건너뜀');

                return;
            }

            if (! $category->is_active || $category->naver_cid !== null || ! $category->seedList()) {
                $this->completeHubItem($item, [], '수집 대상이 아니어서 건너뜀');

                return;
            }

            $stats = $collector->collect($category);
            $this->completeHubItem($item, $stats);
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->failHubItem($this->itemId, $exception);
    }
}
