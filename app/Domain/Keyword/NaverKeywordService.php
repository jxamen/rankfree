<?php

namespace App\Domain\Keyword;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 네이버 검색광고 API(/keywordstool) 키워드 분석 — 월간 검색량·경쟁강도.
 *
 * crm api/naver_ads_api.php 의 HMAC-SHA256 서명 방식을 Laravel 로 이식.
 * 자격증명(.env NAVER_SEARCHAD_*)이 없거나 호출이 실패하면 null 을 반환하고,
 * 클라이언트(크롬 확장)는 카드 비노출로 처리한다.
 */
class NaverKeywordService
{
    /**
     * @return array{
     *     keyword: string, monthly_pc: int, monthly_mobile: int, monthly_total: int,
     *     comp_idx: ?string, related: list<array>
     * }|null
     */
    public function analyze(string $keyword): ?array
    {
        $accounts = $this->accounts();
        if (! $accounts) {
            return null;
        }

        // keywordstool 은 공백을 허용하지 않으며 영문은 대문자로 정규화된다
        $hint = $this->normalize($keyword);
        if ($hint === '') {
            return null;
        }

        $cacheKey = 'searchad:kwtool:'.md5($hint);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // 등록된 계정을 순회 — 하나라도 성공하면 사용(한도/오류 시 다음 계정으로 로테이션)
        foreach ($accounts as $acc) {
            $r = $this->fetch($hint, $acc);
            if ($r !== null) {
                Cache::put($cacheKey, $r, now()->addHours(6));

                return $r;
            }
        }

        return null;
    }

    /** 검색광고 계정 목록 — 다중(accounts) 우선, 없으면 단일 config 폴백. */
    private function accounts(): array
    {
        $base = (string) config('rankfree.searchad.base', 'https://api.searchad.naver.com');
        $timeout = (int) config('rankfree.searchad.timeout', 10);
        $accounts = [];
        foreach ((array) config('rankfree.searchad.accounts', []) as $a) {
            if (! empty($a['api_key']) && ! empty($a['secret_key']) && ! empty($a['customer_id'])) {
                $accounts[] = [
                    'api_key' => $a['api_key'], 'secret_key' => $a['secret_key'], 'customer_id' => $a['customer_id'],
                    'base' => $base, 'timeout' => $timeout,
                ];
            }
        }
        if (! $accounts) {
            $c = (array) config('rankfree.searchad');
            if (! empty($c['api_key']) && ! empty($c['secret_key']) && ! empty($c['customer_id'])) {
                $accounts[] = $c;
            }
        }

        return $accounts;
    }

    private function fetch(string $hint, array $config): ?array
    {
        try {
            $timestamp = (string) round(microtime(true) * 1000);
            $signature = base64_encode(hash_hmac(
                'sha256',
                $timestamp.'.GET./keywordstool',
                (string) $config['secret_key'],
                true,
            ));

            $response = Http::withHeaders([
                'X-Timestamp' => $timestamp,
                'X-API-KEY' => (string) $config['api_key'],
                'X-Customer' => (string) $config['customer_id'],
                'X-Signature' => $signature,
            ])->timeout((int) ($config['timeout'] ?? 10))
                ->get(rtrim((string) $config['base'], '/').'/keywordstool', [
                    'hintKeywords' => $hint,
                    'showDetail' => 1,
                ]);

            if ($response->failed()) {
                Log::warning('searchad keywordstool 실패', ['status' => $response->status()]);

                return null;
            }

            return $this->parse($hint, (array) $response->json('keywordList', []));
        } catch (Throwable $e) {
            Log::warning('searchad keywordstool 예외', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function parse(string $hint, array $keywordList): ?array
    {
        $main = null;
        $related = [];

        foreach ($keywordList as $row) {
            if (! is_array($row) || empty($row['relKeyword'])) {
                continue;
            }

            $item = [
                'keyword' => (string) $row['relKeyword'],
                'monthly_pc' => $this->count($row['monthlyPcQcCnt'] ?? 0),
                'monthly_mobile' => $this->count($row['monthlyMobileQcCnt'] ?? 0),
                'comp_idx' => isset($row['compIdx']) ? (string) $row['compIdx'] : null,
            ];
            $item['monthly_total'] = $item['monthly_pc'] + $item['monthly_mobile'];

            if ($main === null && $this->normalize($item['keyword']) === $hint) {
                $main = $item;
            } else {
                $related[] = $item;
            }
        }

        if ($main === null) {
            // 정확히 일치하는 행이 없으면 첫 행을 대표값으로 사용
            $main = array_shift($related);
        }

        if ($main === null) {
            return null;
        }

        usort($related, fn ($a, $b) => $b['monthly_total'] <=> $a['monthly_total']);
        // 제한 없이 전체 전달 — 입력어 토큰 포함 필터는 presenter(related)에서 수행
        $main['related'] = $related;

        return $main;
    }

    /** "< 10" 같은 절사 표기를 정수로 변환. */
    private function count(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        return 5; // "< 10" — 절사 구간 중앙값으로 추정
    }

    private function normalize(string $keyword): string
    {
        return mb_strtoupper(str_replace(' ', '', trim($keyword)));
    }
}
