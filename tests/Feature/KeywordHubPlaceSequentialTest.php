<?php

namespace Tests\Feature;

use App\Jobs\KeywordHubCollectCategoryJob;
use App\Models\KeywordCategory;
use App\Models\KeywordHubRun;
use App\Models\KeywordHubRunItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 플레이스 후보 수집은 카테고리 순서대로 하나씩 진행한다(2026-07-27).
 * 예전엔 전 카테고리를 한꺼번에 큐에 넣어 워커 여러 개가 동시에 돌면서 순서가 섞였다.
 */
class KeywordHubPlaceSequentialTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => '관리자', 'email' => 'hub'.uniqid().'@rf.kr', 'password' => 'secret1234', 'role' => 'super']);
    }

    /** 시드가 있는 플레이스 카테고리 — seedList() 가 비면 수집 대상에서 빠진다. */
    private function placeCategory(string $name, int $sort): KeywordCategory
    {
        return KeywordCategory::create([
            'type' => 'place',
            'name' => $name,
            'slug' => 'c-'.uniqid(),
            'sort' => $sort,
            'is_active' => true,
            'seed_keywords' => ['맛집', '카페'],
        ]);
    }

    /** 수집 시작 시 플레이스는 **첫 카테고리 하나만** 큐에 들어간다(나머지는 이어달리기). */
    public function test_start_queues_only_first_place_category(): void
    {
        Queue::fake();
        $this->placeCategory('가', 1);
        $this->placeCategory('나', 2);
        $this->placeCategory('다', 3);

        $this->actingAs($this->admin())
            ->post(route('admin.keyword-hub.collect-batch'), ['collect_place' => '1', 'place_limit' => 50])
            ->assertRedirect();

        // 실행 항목은 3개 모두 만들어지되, 큐에는 1건만
        $this->assertSame(3, KeywordHubRunItem::where('type', 'place')->count());
        Queue::assertPushed(KeywordHubCollectCategoryJob::class, 1);
    }

    /** 카테고리 순서(sort)대로 항목이 만들어진다 — 예전 '미수집·오래된 순' 이 아니다. */
    public function test_items_follow_category_sort_order(): void
    {
        Queue::fake();
        // 일부러 최근 수집한 것을 sort 앞에 둔다 — 순서 기준이 sort 임을 확인
        $this->placeCategory('가', 1)->forceFill(['collected_at' => now()])->save();
        $this->placeCategory('나', 2);
        $this->placeCategory('다', 3);

        $this->actingAs($this->admin())
            ->post(route('admin.keyword-hub.collect-batch'), ['collect_place' => '1', 'place_limit' => 50]);

        $labels = KeywordHubRunItem::where('type', 'place')->orderBy('id')->pluck('label')->all();
        $this->assertSame(['가', '나', '다'], $labels);
    }

    /** 한 카테고리가 끝나면 다음 카테고리가 이어서 큐에 들어간다. */
    public function test_job_chains_to_next_category(): void
    {
        $run = KeywordHubRun::create(['status' => 'queued', 'total_jobs' => 3, 'note' => 'test']);
        $items = collect(['가', '나', '다'])->map(fn ($n) => $run->items()->create([
            'type' => 'place', 'target_type' => 'category', 'target_id' => '999999', 'label' => $n,
        ]));

        Queue::fake();
        // 카테고리를 찾을 수 없는 경우(건너뜀)도 다음으로 넘어가야 한다
        (new KeywordHubCollectCategoryJob($items[0]->id))->handle(app(\App\Domain\Keyword\KeywordHubCollector::class));

        Queue::assertPushed(KeywordHubCollectCategoryJob::class, function ($job) use ($items) {
            return $job->itemId === $items[1]->id;
        });
    }

    /** 마지막 카테고리에서는 더 이상 이어달리지 않는다. */
    public function test_last_category_does_not_chain(): void
    {
        $run = KeywordHubRun::create(['status' => 'queued', 'total_jobs' => 1, 'note' => 'test']);
        $item = $run->items()->create([
            'type' => 'place', 'target_type' => 'category', 'target_id' => '999999', 'label' => '끝',
        ]);

        Queue::fake();
        (new KeywordHubCollectCategoryJob($item->id))->handle(app(\App\Domain\Keyword\KeywordHubCollector::class));

        Queue::assertNothingPushed();
    }

    /** 실행이 취소되면 다음 카테고리로 넘어가지 않는다. */
    public function test_cancelled_run_stops_chain(): void
    {
        $run = KeywordHubRun::create(['status' => 'cancelled', 'total_jobs' => 2, 'note' => 'test']);
        $items = collect(['가', '나'])->map(fn ($n) => $run->items()->create([
            'type' => 'place', 'target_type' => 'category', 'target_id' => '999999', 'label' => $n,
        ]));

        Queue::fake();
        (new KeywordHubCollectCategoryJob($items[0]->id))->handle(app(\App\Domain\Keyword\KeywordHubCollector::class));

        Queue::assertNothingPushed();
    }
}
