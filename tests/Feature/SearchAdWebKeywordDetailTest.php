<?php

namespace Tests\Feature;

use App\Domain\SearchAdWeb\SearchAdWebClient;
use App\Domain\SearchAdWeb\WebSessionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SearchAdWebKeywordDetailTest extends TestCase
{
    use RefreshDatabase;

    /** 공백 키워드는 성별/연령/월별을 비워 반환하므로, 요청 전 공백 제거+대문자 정규화해야 한다. */
    public function test_keyword_detail_strips_spaces_before_request(): void
    {
        app(WebSessionStore::class)->save('NID_AUT=x; NID_SES=y');

        $ages = ['0-12', '13-19', '20-24', '25-29', '30-39', '40-49', '50-'];
        Http::fake([
            '*/apis/sa/keywordstool*' => Http::response(['keywordList' => [[
                'relKeyword' => '강남맛집',
                'userStat' => [
                    'genderType' => array_merge(array_fill(0, 7, 'f'), array_fill(0, 7, 'm')),
                    'ageGroup' => array_merge($ages, $ages),
                    'monthlyPcQcCnt' => array_merge([10, 20, 100, 80, 50, 20, 5], array_fill(0, 7, 3)),
                    'monthlyMobileQcCnt' => array_merge([10, 20, 100, 80, 50, 20, 5], array_fill(0, 7, 3)),
                ],
                'monthlyProgressList' => [
                    'monthlyLabel' => ['2025-07', '2025-08'],
                    'monthlyProgressPcQcCnt' => [100, 120],
                    'monthlyProgressMobileQcCnt' => [50, 60],
                ],
            ]]], 200),
        ]);

        $res = app(SearchAdWebClient::class)->keywordDetail('강남 맛집');

        // 요청 URL 의 keyword 파라미터에 공백이 없어야 한다
        Http::assertSent(fn ($req) => str_contains(urldecode($req->url()), 'keyword=강남맛집')
            && ! str_contains(urldecode($req->url()), '강남 맛집'));

        // 파싱 정상 — 14버킷·성별·월별
        $this->assertArrayNotHasKey('error', $res);
        $this->assertCount(14, $res['buckets']);
        $this->assertCount(2, $res['monthly']);
        $this->assertGreaterThan($res['gender']['male_pct'], $res['gender']['female_pct']);
    }
}
