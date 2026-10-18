<?php

namespace Tests\Feature;

use App\Domain\Shopping\ShopExposureRunLock;
use App\Models\ShopKeywordAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopCollectionIpTest extends TestCase
{
    use RefreshDatabase;

    public function test_ip_is_saved_only_for_successful_holder_and_hidden_from_serialization(): void
    {
        $a = ShopKeywordAnalysis::create(['user_id' => User::factory()->create()->id, 'core_keyword' => '테스트', 'product_url' => 'https://example.com', 'status' => 'checking']);
        $lock = app(ShopExposureRunLock::class);
        $lock->acquire('ip:203.0.113.18', 'ext:test', $a->id);
        $a->refresh();
        $this->assertSame('203.0.113.18', $a->collection_ip);
        $this->assertSame('확장 수집기', $a->collection_source);
        $this->assertArrayNotHasKey('collection_ip', $a->toArray());
        $busy = $lock->acquire('ip:203.0.113.18', 'page:other', $a->id);
        $this->assertFalse($busy['ok']);
        $this->assertStringContainsString('203.0.113.18', $lock->busyMessage($busy['holder'], true));
        $this->assertStringNotContainsString('203.0.113.18', $lock->busyMessage($busy['holder']));
        $this->assertStringContainsString('마스킹', $lock->busyMessage($busy['holder']));
        $this->assertSame('확장 수집기', $a->fresh()->collection_source);
    }
}
