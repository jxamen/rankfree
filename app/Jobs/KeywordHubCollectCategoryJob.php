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

        // 이 카테고리가 끝나면(성공·건너뜀 무관) 같은 실행의 다음 카테고리를 이어서 돌린다.
        // 관리자 OFF 로 재시도(release) 하는 경우는 위에서 이미 return 했으므로 여기 오지 않는다.
        try {
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
        } finally {
            $this->dispatchNextPlaceItem($item);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->failHubItem($this->itemId, $exception);

        // 한 카테고리가 끝내 실패해도 나머지가 멈추면 안 된다 — 다음 카테고리로 넘어간다.
        $item = KeywordHubRunItem::find($this->itemId);
        if ($item) {
            $this->dispatchNextPlaceItem($item);
        }
    }

    /**
     * 같은 실행(run)에서 아직 시작하지 않은 다음 플레이스 카테고리를 큐에 넣는다(2026-07-27).
     * 카테고리 순서대로 하나씩 진행하기 위한 이어달리기 — 컨트롤러는 첫 카테고리만 넣는다.
     */
    private function dispatchNextPlaceItem(KeywordHubRunItem $item): void
    {
        if ($item->run?->status === 'cancelled') {
            return;
        }

        $next = KeywordHubRunItem::where('run_id', $item->run_id)
            ->where('type', 'place')
            ->where('id', '>', $item->id)
            ->where('status', 'queued')
            ->orderBy('id')
            ->first();

        if ($next) {
            self::dispatch($next->id);
        }
    }
}
