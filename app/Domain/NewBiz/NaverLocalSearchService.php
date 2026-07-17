<?php

namespace App\Domain\NewBiz;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 네이버 지역검색 **공식** OpenAPI(openapi.naver.com/v1/search/local.json) — 상호명으로 업체를 찾는다.
 * 쇼핑 검색과 같은 개발자센터 키(rankfree.shopping.api_keys)를 쓴다.
 *
 * ⚠️ pcmap 순위검색(PlaceRankChecker)으로는 상호 검색이 안 된다(실측: '신천동 맛집' 11,758건 정상 /
 *    '조선솥밥' 0건). 순위 API 는 "지역+업종" 키워드용이라 신규 업소 매칭에 쓰면 전부 '미등록' 오판이 난다.
 * 응답에 플레이스 ID 는 없다 — 존재 여부·상호·주소·분류만 확인하고 링크는 지도 검색으로 연결한다.
 *
 * @see https://developers.naver.com/docs/serviceapi/search/local/
 */
class NaverLocalSearchService
{
    /** @return list<array{title:string,category:string,address:string,road_address:string,link:string}> */
    public function search(string $query, int $display = 5): array
    {
        $keys = (array) config('rankfree.shopping.api_keys', []);
        if (! $keys || trim($query) === '') {
            return [];
        }
        $k = $keys[array_rand($keys)];   // 다중 키 로테이션(쇼핑 수집과 동일)

        try {
            $r = Http::withHeaders(['X-Naver-Client-Id' => $k['id'], 'X-Naver-Client-Secret' => $k['secret']])
                ->timeout(15)->get('https://openapi.naver.com/v1/search/local.json', [
                    'query' => $query, 'display' => max(1, min(5, $display)),
                ]);
            if (! $r->successful()) {
                Log::warning('newbiz: local search error', ['status' => $r->status(), 'q' => $query]);

                return [];
            }

            return collect((array) $r->json('items', []))->map(fn ($i) => [
                'title' => strip_tags((string) ($i['title'] ?? '')),
                'category' => (string) ($i['category'] ?? ''),
                'address' => (string) ($i['address'] ?? ''),
                'road_address' => (string) ($i['roadAddress'] ?? ''),
                'link' => (string) ($i['link'] ?? ''),
            ])->all();
        } catch (\Throwable $e) {
            Log::warning('newbiz: local search failed', ['q' => $query, 'msg' => $e->getMessage()]);

            return [];
        }
    }
}
