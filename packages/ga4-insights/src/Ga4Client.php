<?php

namespace Jcurve\Ga4Insights;

use Illuminate\Support\Facades\Http;
use Jcurve\Ga4Insights\Contracts\Ga4Credentials;

/**
 * GA4 Data API(v1beta) 얇은 HTTP 클라이언트 — batchRunReports / runRealtimeReport.
 * 인증·속성ID는 Ga4Credentials 로만 얻는다(프레임워크 외 의존 없음).
 */
class Ga4Client
{
    private const BASE = 'https://analyticsdata.googleapis.com/v1beta/properties';

    public function __construct(private Ga4Credentials $creds) {}

    public function credentials(): Ga4Credentials
    {
        return $this->creds;
    }

    /**
     * 여러 runReport 요청을 한 번에(최대 5개/호출). $requests 각 항목은 GA4 runReport 바디.
     *
     * @return array<int,array> reports (요청 순서와 동일)
     *
     * @throws Ga4Exception
     */
    public function batch(array $requests): array
    {
        $out = [];
        foreach (array_chunk($requests, 5) as $chunk) {
            $res = $this->post('batchRunReports', ['requests' => array_values($chunk)]);
            foreach ((array) ($res['reports'] ?? []) as $rep) {
                $out[] = $rep;
            }
        }

        return $out;
    }

    /** 실시간 리포트(runRealtimeReport). 실패 시 빈 배열(실시간은 선택 정보라 예외 대신 무시). */
    public function realtime(array $request): array
    {
        try {
            return $this->post('runRealtimeReport', $request);
        } catch (Ga4Exception) {
            return [];
        }
    }

    private function post(string $method, array $body): array
    {
        $pid = $this->creds->propertyId();
        $token = $this->creds->accessToken();
        if ($pid === null) {
            throw new Ga4Exception('GA4 속성 ID가 설정되지 않았습니다.');
        }
        if ($token === null) {
            throw new Ga4Exception('구글 인증 토큰을 얻지 못했습니다(연동/권한 확인).');
        }

        $res = Http::withToken($token)->timeout(30)
            ->post(self::BASE."/{$pid}:{$method}", $body);

        if (! $res->successful()) {
            $msg = (string) ($res->json('error.message') ?? mb_substr($res->body(), 0, 300));
            throw new Ga4Exception("GA4 API 오류 (HTTP {$res->status()}): {$msg}", $res->status());
        }

        return (array) $res->json();
    }
}
