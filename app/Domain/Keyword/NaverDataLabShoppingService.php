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
     * 실측(2026-07): 분야당 최대 25페이지(500위)까지 제공, 그 뒤는 빈 배열.
     * ⚠️ 데이터랩은 초당 수 회 연속 요청에 429 를 반환 — 페이지 간 600ms + 429 백오프 재시도.
     * 부분 수집(중간 실패)은 캐시하지 않는다(다음 실행에서 온전히 재수집).
     *
     * @return list<array{rank:int,keyword:string}>
     */
    public function topKeywords(int $cid, int $pages = 25): array
    {
        $pages = max(1, min($pages, 25)); // 페이지당 20개 · 상한 500개(실측 최대)
        $key = "datalab:shop:rank:{$cid}:{$pages}";
        $hit = Cache::get($key);
        if ($hit !== null) {
            return $hit;
        }

        [$out, $complete] = $this->fetchRanks($cid, $pages);
        if ($complete) {
            Cache::put($key, $out, now()->addHours(12));
        }

        return $out;
    }

    /** @return array{0: list<array{rank:int,keyword:string}>, 1: bool} [결과, 완주 여부] */
    private function fetchRanks(int $cid, int $pages): array
    {
        $out = [];
        for ($page = 1; $page <= $pages; $page++) {
            if ($page > 1) {
                usleep(600_000); // 페이지 간 대기 — 429 방지(실측 150ms 간격은 차단됨)
            }
            $resp = null;
            for ($try = 0; $try <= 3; $try++) {
                if ($try > 0) {
                    sleep(4 * $try); // 429 백오프 — 4·8·12초
                }
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
                } catch (\Throwable $e) {
                    Log::warning('datalab shop: rank failed', ['cid' => $cid, 'page' => $page, 'msg' => $e->getMessage()]);

                    return [$out, false];
                }
                if ($resp->status() !== 429) {
                    break;
                }
            }
            if (! $resp || ! $resp->successful() || (int) $resp->json('returnCode', -1) !== 0) {
                Log::warning('datalab shop: rank error', ['cid' => $cid, 'page' => $page, 'status' => $resp?->status()]);

                return [$out, false];
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
        }

        return [$out, true];
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
