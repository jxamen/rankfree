<?php

namespace App\Domain\Keyword;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 네이버 콘텐츠 발행량(통합 문서 수) — openapi 검색 total(블로그·카페).
 * 콘텐츠 포화 지수(발행량 ÷ 월검색량)에 사용. 검색 API 키(config rankfree.shopping.api_keys) 공유,
 * 429 시 다음 키 로테이션, 6시간 캐시. 가벼운 2요청(크롤링·페이지네이션 없음).
 */
class NaverContentVolumeService
{
    /** @return array{blog:int,cafe:int,total:int}|null */
    public function counts(string $keyword): ?array
    {
        $kw = trim($keyword);
        if ($kw === '') {
            return null;
        }
        // 키 없으면 결과를 캐시하지 않는다 — 환경 설정에서 키를 넣으면 다음 요청에 즉시 반영.
        $keys = (array) config('rankfree.shopping.api_keys');
        if (! $keys) {
            return null;
        }

        return Cache::remember('kw:content:'.md5(mb_strtoupper(str_replace(' ', '', $kw))), now()->addHours(6), function () use ($kw, $keys) {
            $blog = $this->total('blog.json', $kw, $keys);
            $cafe = $this->total('cafearticle.json', $kw, $keys);
            if ($blog === null && $cafe === null) {
                return null;
            }

            return ['blog' => (int) $blog, 'cafe' => (int) $cafe, 'total' => (int) $blog + (int) $cafe];
        });
    }

    /** 쇼핑 상품 수(openapi shop.json total) — 상업성(구매 의도) 판정 신호. 실패 null. 6시간 캐시. */
    public function shopTotal(string $keyword): ?int
    {
        $kw = trim($keyword);
        if ($kw === '') {
            return null;
        }
        $keys = (array) config('rankfree.shopping.api_keys');
        if (! $keys) {
            return null; // 키 없으면 캐시하지 않음(설정 즉시 반영)
        }

        return Cache::remember('kw:shoptotal:'.md5(mb_strtoupper(str_replace(' ', '', $kw), 'UTF-8')), now()->addHours(6), function () use ($kw, $keys) {
            return $this->total('shop.json', $kw, $keys);
        });
    }

    /**
     * 인기글 TOP — openapi 블로그·카페 관련도(sim) 상위글. 이슈성/광고 오염 없는 깨끗한 소스.
     *
     * @return list<array{title:string,link:string,source:string,author:string,date:?string}>
     */
    public function popular(string $keyword, int $limit = 7): array
    {
        $kw = trim($keyword);
        if ($kw === '') {
            return [];
        }
        $keys = (array) config('rankfree.shopping.api_keys');
        if (! $keys) {
            return []; // 키 없으면 캐시하지 않음(설정 즉시 반영)
        }

        return Cache::remember('kw:popular:'.md5(mb_strtoupper(str_replace(' ', '', $kw))).':'.$limit, now()->addHours(6), function () use ($kw, $limit, $keys) {
            $each = (int) max(3, ceil($limit / 2) + 1);
            $blog = $this->items('blog.json', $kw, $keys, $each, '블로그');
            $cafe = $this->items('cafearticle.json', $kw, $keys, $each, '카페');

            // 블로그·카페 교차 배치(블로그 우선), limit 컷
            $out = [];
            $max = max(count($blog), count($cafe));
            for ($i = 0; $i < $max; $i++) {
                if (isset($blog[$i])) {
                    $out[] = $blog[$i];
                }
                if (isset($cafe[$i])) {
                    $out[] = $cafe[$i];
                }
            }

            return array_slice($out, 0, $limit);
        });
    }

    /** openapi 검색 결과 아이템(sim 정렬) → 정제. */
    private function items(string $endpoint, string $kw, array $keys, int $display, string $source): array
    {
        foreach ($keys as $key) {
            try {
                $r = Http::withHeaders([
                    'X-Naver-Client-Id' => (string) $key['id'],
                    'X-Naver-Client-Secret' => (string) $key['secret'],
                ])->timeout((int) config('rankfree.shopping.timeout', 15))
                    ->get('https://openapi.naver.com/v1/search/'.$endpoint, ['query' => $kw, 'display' => $display, 'sort' => 'sim']);

                if ($r->status() === 429) {
                    continue;
                }
                if (! $r->ok()) {
                    return [];
                }

                $out = [];
                foreach ((array) $r->json('items', []) as $it) {
                    $title = trim(html_entity_decode(strip_tags((string) ($it['title'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $link = (string) ($it['link'] ?? '');
                    if ($title === '' || $link === '') {
                        continue;
                    }
                    $date = null;
                    if (! empty($it['postdate']) && preg_match('/^(\d{4})(\d{2})(\d{2})$/', (string) $it['postdate'], $mm)) {
                        $date = $mm[1].'.'.$mm[2].'.'.$mm[3];
                    }
                    $out[] = [
                        'title' => $title,
                        'link' => $link,
                        'source' => $source,
                        'author' => trim(html_entity_decode(strip_tags((string) ($it['bloggername'] ?? $it['cafename'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                        'date' => $date,
                    ];
                }

                return $out;
            } catch (Throwable) {
                return [];
            }
        }

        return [];
    }

    /** openapi 검색 total(누적 문서 수). 429 면 다음 키. 실패 null. */
    private function total(string $endpoint, string $kw, array $keys): ?int
    {
        foreach ($keys as $key) {
            try {
                $r = Http::withHeaders([
                    'X-Naver-Client-Id' => (string) $key['id'],
                    'X-Naver-Client-Secret' => (string) $key['secret'],
                ])->timeout((int) config('rankfree.shopping.timeout', 15))
                    ->get('https://openapi.naver.com/v1/search/'.$endpoint, ['query' => $kw, 'display' => 1]);

                if ($r->status() === 429) {
                    continue;
                }

                return $r->ok() ? (int) $r->json('total', 0) : null;
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
