<?php

namespace Tests\Feature;

use App\Jobs\EnrichMarketKeywordData;
use App\Models\MarketAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 시장분석 공유 페이지 첫 로드 성능(2026-07-23) — 키워드 데이터 보강은 요청 안에서 크롤하지 않고
 * 백그라운드 잡으로 예약한다(잡 중복 예약 가드 포함). 페이지는 즉시 렌더된다.
 */
class MarketSharedFastLoadTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_view_dispatches_enrich_job_and_renders_immediately(): void
    {
        Queue::fake();
        $u = User::create(['name' => 'u', 'email' => 'mf@rf.kr', 'password' => 'x1234567']);
        $a = MarketAnalysis::create(['user_id' => $u->id, 'keyword' => '버버리가방', 'sales_6m' => 777,
            'snapshot' => ['top_products' => [['title' => '가방A', 'price' => 100, 'purchase6m' => 2]]]]);

        $this->get('/market/'.rawurlencode('버버리가방'))->assertOk()->assertSee('가방A');

        Queue::assertPushed(EnrichMarketKeywordData::class, fn ($job) => $job->analysisId === $a->id);

        // 15분 중복 예약 가드 — 연속 열람에도 잡은 1회만
        $this->get('/market/'.rawurlencode('버버리가방'))->assertOk();
        Queue::assertPushed(EnrichMarketKeywordData::class, 1);
    }

    public function test_crawler_enriches_synchronously_instead_of_queue(): void
    {
        Queue::fake();
        \Illuminate\Support\Facades\Http::fake();   // 외부 크롤 차단 — ensure 는 동기 호출되되 데이터는 못 채움(분기 검증엔 무관)
        $u = User::create(['name' => 'u', 'email' => 'mfbot@rf.kr', 'password' => 'x1234567']);
        MarketAnalysis::create(['user_id' => $u->id, 'keyword' => '크롤러키워드', 'sales_6m' => 5,
            'snapshot' => ['top_products' => [['title' => '상품X', 'price' => 100, 'purchase6m' => 1]]]]);

        // 크롤러(Googlebot) UA — keyword_data 가 비어 있으면 그 자리에서 동기 보강하므로 큐에 잡을 던지지 않는다.
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'])
            ->get('/market/'.rawurlencode('크롤러키워드'))->assertOk();

        Queue::assertNotPushed(EnrichMarketKeywordData::class);   // 봇은 동기 — 큐 미사용
    }

    public function test_enriched_doc_does_not_dispatch(): void
    {
        Queue::fake();
        $u = User::create(['name' => 'u', 'email' => 'mf2@rf.kr', 'password' => 'x1234567']);
        MarketAnalysis::create(['user_id' => $u->id, 'keyword' => '완료키워드', 'sales_6m' => 10,
            'snapshot' => [
                'top_products' => [],
                'keyword_data' => ['monthly_total' => 5000, 'detail' => ['gender' => ['f' => 70]]],
            ]]);

        $this->get('/market/'.rawurlencode('완료키워드'))->assertOk();

        Queue::assertNothingPushed();
    }
}
