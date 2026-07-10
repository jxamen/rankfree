<?php

namespace App\Domain\SearchAd;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * 네이버 검색광고 공식 API 범용 클라이언트 (HMAC-SHA256 서명).
 * 콘솔·크롬 확장·배치 등 "여러 곳"에서 어떤 엔드포인트든 서명 호출로 재사용한다.
 *
 * 서명: X-Signature = base64(hmac_sha256(secret, "{timestamp}.{METHOD}.{path}"))
 *   - {path} 는 쿼리스트링을 제외한 URI (예: /keywordstool)  [공식 php-sample Signature 동일]
 * 헤더: X-Timestamp, X-API-KEY, X-Customer, X-Signature
 * 설정: config('rankfree.searchad')  (.env NAVER_SEARCHAD_*)
 *
 * 라이브 검증(2026-07): /keywordstool 200(연관 1,200), /ncc/campaigns 200 — 서명 범용성 확인.
 */
class SearchAdClient
{
    private string $apiKey;
    private string $secret;
    private string $customerId;
    private string $base;
    private int $timeout;

    public function __construct(?array $config = null)
    {
        $c = $config ?? (array) config('rankfree.searchad');
        $this->apiKey = (string) ($c['api_key'] ?? '');
        $this->secret = (string) ($c['secret_key'] ?? '');
        $this->customerId = (string) ($c['customer_id'] ?? '');
        $this->base = rtrim((string) ($c['base'] ?? 'https://api.searchad.naver.com'), '/');
        $this->timeout = (int) ($c['timeout'] ?? 10);
    }

    public function configured(): bool
    {
        return $this->apiKey !== '' && $this->secret !== '' && $this->customerId !== '';
    }

    /** 서명 헤더 생성 (path 는 쿼리 제외 경로). */
    public function signedHeaders(string $method, string $path): array
    {
        $ts = (string) round(microtime(true) * 1000);
        $sig = base64_encode(hash_hmac('sha256', $ts.'.'.strtoupper($method).'.'.$path, $this->secret, true));

        return [
            'X-Timestamp' => $ts,
            'X-API-KEY' => $this->apiKey,
            'X-Customer' => $this->customerId,
            'X-Signature' => $sig,
        ];
    }

    /**
     * 임의 엔드포인트 서명 호출.
     *
     * @param  string  $path  쿼리 제외 경로 ('/keywordstool', '/ncc/campaigns', '/stats' …)
     */
    public function request(string $method, string $path, array $query = [], array $body = []): Response
    {
        $method = strtoupper($method);
        $http = Http::withHeaders($this->signedHeaders($method, $path))
            ->timeout($this->timeout)
            ->baseUrl($this->base)
            ->acceptJson();

        return match ($method) {
            'GET' => $http->get($path, $query),
            'DELETE' => $http->delete($path, $query),
            'POST' => $http->withQueryParameters($query)->post($path, $body),
            'PUT' => $http->withQueryParameters($query)->put($path, $body),
            default => $http->send($method, $path, ['query' => $query, 'json' => $body]),
        };
    }

    public function get(string $path, array $query = []): Response
    {
        return $this->request('GET', $path, $query);
    }

    public function post(string $path, array $body = [], array $query = []): Response
    {
        return $this->request('POST', $path, $query, $body);
    }

    /**
     * 키워드 도구 — 월간 검색량·경쟁강도·연관키워드 원본(keywordList).
     * 파싱/캐시는 호출자(App\Domain\Keyword\NaverKeywordService 등) 담당.
     *
     * @param  list<string>  $hintKeywords  최대 5개, 공백 제거
     * @return list<array>
     */
    public function keywordTool(array $hintKeywords, bool $showDetail = true): array
    {
        $hints = array_slice(array_values(array_filter(array_map(
            fn ($k) => str_replace(' ', '', trim((string) $k)),
            $hintKeywords,
        ), 'strlen')), 0, 5);

        if (! $hints) {
            return [];
        }

        $resp = $this->get('/keywordstool', [
            'hintKeywords' => implode(',', $hints),
            'showDetail' => $showDetail ? 1 : 0,
        ]);

        return $resp->ok() ? (array) $resp->json('keywordList', []) : [];
    }
}
