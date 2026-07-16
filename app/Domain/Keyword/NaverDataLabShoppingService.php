<?php

namespace App\Domain\Keyword;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 네이버 데이터랩 쇼핑인사이트(비공식, 무로그인) — 분야(1~3분류) 트리 + 분야별 인기검색어.
 *   GET  /shoppingInsight/getCategory.naver?cid={cid}          → childList(하위 분야)
 *   POST /shoppingInsight/getCategoryKeywordRank.naver         → ranks(인기검색어 TOP, page×20)
 * cid=0 이 1분류 루트. referer·XHR 헤더 + 전체 UA 필수(쿠키 불필요 — 실측 2026-07).
 * 응답 파싱은 네이버 변경 시 깨질 수 있음 — 실패는 빈 배열로 폴백하고 로그만 남긴다.
 */
class NaverDataLabShoppingService
{
    private const BASE = 'https://datalab.naver.com/shoppingInsight';

    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36';

    /**
     * 하위 분야 목록. cid=0 → 1분류.
     *
     * @return list<array{cid:int,name:string,leaf:bool}>
     */
    public function children(int $cid): array
    {
        return Cache::remember('datalab:shop:cat:'.$cid, now()->addHours(24), function () use ($cid) {
            try {
                $resp = Http::withHeaders($this->headers())->timeout(15)
                    ->get(self::BASE.'/getCategory.naver', ['cid' => $cid]);
                if (! $resp->successful()) {
                    Log::warning('datalab shop: category error', ['cid' => $cid, 'status' => $resp->status()]);

                    return [];
                }

                return collect($resp->json('childList') ?? [])
                    ->filter(fn ($c) => ! empty($c['cid']) && ($c['name'] ?? '') !== '' && empty($c['deleted']))
                    ->map(fn ($c) => ['cid' => (int) $c['cid'], 'name' => (string) $c['name'], 'leaf' => (bool) ($c['leaf'] ?? false)])
                    ->values()->all();
            } catch (\Throwable $e) {
                Log::warning('datalab shop: category failed', ['cid' => $cid, 'msg' => $e->getMessage()]);

                return [];
            }
        });
    }

    /**
     * 분야 인기검색어 — 최근 30일 기준, page×20개.
     *
     * @return list<array{rank:int,keyword:string}>
     */
    public function topKeywords(int $cid, int $pages = 1): array
    {
        $pages = max(1, min($pages, 5)); // 페이지당 20개 · 상한 100개

        return Cache::remember("datalab:shop:rank:{$cid}:{$pages}", now()->addHours(12), function () use ($cid, $pages) {
            $out = [];
            for ($page = 1; $page <= $pages; $page++) {
                try {
                    $resp = Http::asForm()->withHeaders($this->headers() + ['origin' => 'https://datalab.naver.com'])
                        ->timeout(15)
                        ->post(self::BASE.'/getCategoryKeywordRank.naver', [
                            'cid' => $cid,
                            'timeUnit' => 'date',
                            'startDate' => now()->subDays(30)->toDateString(),
                            'endDate' => now()->subDay()->toDateString(),
                            'age' => '', 'gender' => '', 'device' => '',
                            'page' => $page,
                            'count' => 20,
                        ]);
                    if (! $resp->successful() || (int) $resp->json('returnCode', -1) !== 0) {
                        Log::warning('datalab shop: rank error', ['cid' => $cid, 'status' => $resp->status()]);
                        break;
                    }
                    $ranks = (array) ($resp->json('ranks') ?? []);
                    foreach ($ranks as $r) {
                        if (($r['keyword'] ?? '') !== '') {
                            $out[] = ['rank' => (int) ($r['rank'] ?? 0), 'keyword' => (string) $r['keyword']];
                        }
                    }
                    if (count($ranks) < 20) {
                        break; // 마지막 페이지
                    }
                } catch (\Throwable $e) {
                    Log::warning('datalab shop: rank failed', ['cid' => $cid, 'msg' => $e->getMessage()]);
                    break;
                }
            }

            return $out;
        });
    }

    private function headers(): array
    {
        return [
            'accept' => '*/*',
            'referer' => self::BASE.'/sCategory.naver',
            'x-requested-with' => 'XMLHttpRequest',
            'user-agent' => self::UA,
        ];
    }
}
