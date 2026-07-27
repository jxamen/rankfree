<?php

namespace Tests\Feature;

use App\Models\AiCrawlerHit;
use App\Support\Ga4AiDiscoveryProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AI Discovery = AI Referral + Generative Organic (2026-07-27).
 *  - AI Referral        : GA4 소스/매체 중 AI 서비스에서 온 방문(세션)
 *  - Generative Organic : AI 크롤러·에이전트가 문서를 직접 읽어간 요청(GA4 미집계 → 서버 로깅)
 */
class AiDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    /** UA → 봇 판별. 일반 브라우저는 잡히면 안 된다. */
    public function test_detects_ai_agents_only(): void
    {
        $this->assertSame('GPTBot', AiCrawlerHit::detect('Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)'));
        $this->assertSame('OAI-SearchBot', AiCrawlerHit::detect('Mozilla/5.0 (compatible; OAI-SearchBot/1.0)'));
        $this->assertSame('ChatGPT-User', AiCrawlerHit::detect('Mozilla/5.0 (compatible; ChatGPT-User/1.0)'));
        $this->assertSame('ClaudeBot', AiCrawlerHit::detect('Mozilla/5.0 (compatible; ClaudeBot/1.0)'));
        $this->assertSame('PerplexityBot', AiCrawlerHit::detect('Mozilla/5.0 (compatible; PerplexityBot/1.0)'));

        // 사람이 쓰는 브라우저는 절대 집계되면 안 된다
        $this->assertNull(AiCrawlerHit::detect('Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120 Safari/537.36'));
        $this->assertNull(AiCrawlerHit::detect(''));
    }

    /** 요청이 실제로 기록되고, 같은 날 같은 경로는 누적된다. */
    public function test_middleware_records_hits(): void
    {
        $ua = 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)';

        $this->withHeaders(['User-Agent' => $ua])->get('/');
        $this->withHeaders(['User-Agent' => $ua])->get('/');

        $row = AiCrawlerHit::where('bot', 'GPTBot')->first();
        $this->assertNotNull($row);
        $this->assertSame(2, $row->hits);
        $this->assertSame('/', $row->path);
    }

    /** 사람 방문은 기록하지 않는다. */
    public function test_human_visit_not_recorded(): void
    {
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh) Chrome/120 Safari/537.36'])->get('/');

        $this->assertSame(0, AiCrawlerHit::count());
    }

    /** GA4 소스/매체에서 AI 서비스만 골라 합산한다(대소문자·표기 흔들림 흡수). */
    public function test_referral_rows_from_ga4_source_medium(): void
    {
        $ga = ['sourceMedium' => [
            ['name' => 'chatgpt.com / referral', 'sessions' => 12, 'users' => 9],
            ['name' => 'Perplexity.ai / referral', 'sessions' => 5, 'users' => 4],
            ['name' => 'google / organic', 'sessions' => 300, 'users' => 250],
            ['name' => 'chat.openai.com / referral', 'sessions' => 3, 'users' => 3],
            ['name' => '(direct) / (none)', 'sessions' => 100, 'users' => 80],
        ]];

        $out = (new Ga4AiDiscoveryProvider)->summary('2026-07-01', '2026-07-27', $ga);

        // ChatGPT 는 chatgpt.com + chat.openai.com 합산(12+3), 검색·직접유입은 제외
        $names = array_column($out['referral'], 'name');
        $this->assertSame(['ChatGPT', 'Perplexity'], $names);
        $this->assertSame(15, $out['referral'][0]['sessions']);
        $this->assertSame(20, $out['totals']['referral_sessions']);
    }

    /** 크롤러 집계 — 봇별 합계·사용자 요청형 분리·많이 읽힌 문서. */
    public function test_generative_rows_from_crawler_hits(): void
    {
        AiCrawlerHit::create(['hit_date' => '2026-07-20', 'bot' => 'GPTBot', 'path' => '/market/여름이불', 'hits' => 30]);
        AiCrawlerHit::create(['hit_date' => '2026-07-21', 'bot' => 'GPTBot', 'path' => '/keyword/전기장판', 'hits' => 12]);
        AiCrawlerHit::create(['hit_date' => '2026-07-21', 'bot' => 'ChatGPT-User', 'path' => '/market/여름이불', 'hits' => 7]);
        AiCrawlerHit::create(['hit_date' => '2026-06-01', 'bot' => 'GPTBot', 'path' => '/old', 'hits' => 999]);   // 기간 밖

        $out = (new Ga4AiDiscoveryProvider)->summary('2026-07-01', '2026-07-27', []);

        $this->assertSame(49, $out['totals']['generative_hits']);      // 30+12+7 (기간 밖 제외)
        $this->assertSame(7, $out['totals']['user_agent_hits']);       // ChatGPT-User 만
        $this->assertSame('GPTBot', $out['generative'][0]['name']);
        $this->assertSame('crawl', $out['generative'][0]['kind']);
        $this->assertSame('user', collect($out['generative'])->firstWhere('name', 'ChatGPT-User')['kind']);

        // 가장 많이 읽힌 문서(경로별 합산: 여름이불 30+7=37)
        $this->assertSame('/market/여름이불', $out['pages'][0]['name']);
        $this->assertSame(37, $out['pages'][0]['hits']);
    }

    /** 데이터가 없어도 구조는 유지된다(대시보드가 빈 표를 그린다). */
    public function test_empty_summary_shape(): void
    {
        $out = (new Ga4AiDiscoveryProvider)->summary('2026-07-01', '2026-07-27', []);

        $this->assertSame([], $out['referral']);
        $this->assertSame([], $out['generative']);
        $this->assertSame(0, $out['totals']['referral_sessions']);
        $this->assertSame(0, $out['totals']['generative_hits']);
    }
}
