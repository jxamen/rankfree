<?php

namespace App\Domain\Keyword;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 네이버 데이터랩 검색어 트렌드(openapi /v1/datalab/search) — 요일별 검색 비율.
 * timeUnit=date 로 최근 90일 일별 비율을 받아 요일(월~일)로 집계·정규화.
 * 검색 API 키(config rankfree.shopping.api_keys) 공유 — 단, 데이터랩 스코프가 있는 키만 200,
 * 스코프 없는 키는 401(errorCode 024) → 다음 키 로테이션. 24시간 캐시(요일 패턴은 안정적).
 */
class NaverDataLabService
{
    private const WD = ['월', '화', '수', '목', '금', '토', '일'];

    /**
     * 요일별 검색 비율(월~일) — 합계 100% 정규화.
     *
     * @return list<array{w:string,pct:float}>|null
     */
    public function weekdayRatio(string $keyword): ?array
    {
        $kw = trim($keyword);
        if ($kw === '') {
            return null;
        }
        // 키 없으면 캐시하지 않음 — 환경 설정에서 키를 넣으면 다음 요청에 즉시 반영.
        $keys = (array) config('rankfree.shopping.api_keys');
        if (! $keys) {
            return null;
        }

        // 실패도 30분 네거티브 캐시 — 키 쿼터 소진(429) 중에 공유 페이지가 매 요청 라이브 호출로 느려지지 않게.
        // Cache::remember 는 null 을 저장하지 못해(다음 요청 재호출) 래퍼 배열로 감싼다.
        $ck = 'kw:weekday:v2:'.md5(mb_strtoupper(str_replace(' ', '', $kw)));
        $hit = Cache::get($ck);
        if (is_array($hit) && array_key_exists('v', $hit)) {
            return $hit['v'];
        }
        $out = $this->computeWeekday($kw, $keys);
        Cache::put($ck, ['v' => $out], $out !== null ? now()->addHours(24) : now()->addMinutes(30));

        return $out;
    }

    /** @return list<array{w: string, pct: float}>|null */
    private function computeWeekday(string $kw, array $keys): ?array
    {
        return (function () use ($kw, $keys) {
            $end = CarbonImmutable::yesterday();
            $start = $end->subDays(90);
            $body = json_encode([
                'startDate' => $start->format('Y-m-d'),
                'endDate' => $end->format('Y-m-d'),
                'timeUnit' => 'date',
                'keywordGroups' => [['groupName' => $kw, 'keywords' => [$kw]]],
            ], JSON_UNESCAPED_UNICODE);

            $data = $this->fetch($body, $keys);
            if (! $data) {
                return null;
            }

            $wd = array_fill(0, 7, 0.0);
            foreach ($data as $d) {
                if (empty($d['period'])) {
                    continue;
                }
                $w = (int) date('N', strtotime((string) $d['period'])) - 1;   // 0=월 … 6=일
                if ($w >= 0 && $w <= 6) {
                    $wd[$w] += (float) ($d['ratio'] ?? 0);
                }
            }
            $sum = array_sum($wd) ?: 1;
            $out = [];
            foreach ($wd as $i => $v) {
                $out[] = ['w' => self::WD[$i], 'pct' => round($v / $sum * 100, 1)];
            }

            return $out;
        })();
    }

    /** datalab/search POST — 스코프 있는 키가 나올 때까지 로테이션. results.0.data 반환. */
    private function fetch(string $body, array $keys): ?array
    {
        foreach ($keys as $key) {
            try {
                $r = Http::withHeaders([
                    'X-Naver-Client-Id' => (string) $key['id'],
                    'X-Naver-Client-Secret' => (string) $key['secret'],
                    'Content-Type' => 'application/json',
                ])->timeout((int) config('rankfree.shopping.timeout', 15))
                    ->withBody($body, 'application/json')
                    ->post('https://openapi.naver.com/v1/datalab/search');

                if ($r->status() === 401 || $r->status() === 429) {
                    continue;   // 스코프 없음/한도 → 다음 키
                }
                if (! $r->ok()) {
                    return null;
                }

                return (array) $r->json('results.0.data', []);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
