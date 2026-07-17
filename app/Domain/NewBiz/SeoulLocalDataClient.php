<?php

namespace App\Domain\NewBiz;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 서울 열린데이터광장 인허가 API — 지방행정 인허가데이터(LOCALDATA) 서울 제공분.
 *   GET {base}/{KEY}/json/{서비스명}/{start}/{end}/{인허가일자}
 * 실측(2026-07): 첫 선택인자가 **인허가일자 필터**로 동작하고 접두 매칭도 된다.
 *   '2026-07-10' → 그날 인허가 48건 · '2026-07' → 그달 483건 (전체 스캔 불필요)
 * 응답 컬럼: MGTNO·BPLCNM·APVPERMYMD·TRDSTATENM·SITETEL·SITEWHLADDR·RDNWHLADDR·UPTAENM·UPDATEGBN·UPDATEDT …
 *
 * ⚠️ 전국판(LOCALDATA 포털 openDataApi)은 2026-04 포털 폐쇄로 응답하지 않는다(실측). 전국 확장은
 *    data.go.kr 표준데이터 인증키 발급 후 별도 클라이언트로 붙인다.
 * ⚠️ 인증키가 'sample' 이면 응답이 5건으로 제한된다(총건수 list_total_count 는 실제값).
 */
class SeoulLocalDataClient
{
    /** @return array{total:int, rows:list<array<string,string>>, error:?string} */
    public function fetch(string $svc, string $dateFilter, int $start = 1, int $end = 1000): array
    {
        $key = $this->key();
        $base = rtrim((string) config('rankfree.newbiz.seoul_base'), '/');
        $url = "{$base}/{$key}/json/{$svc}/{$start}/{$end}/".rawurlencode($dateFilter);

        try {
            // ⚠️ decode_content=false 필수 — Guzzle 기본값(true)이면 curl 이 Accept-Encoding 을 붙이는데,
            //    서울 API 응답 인코딩을 curl 이 못 풀어 "cURL error 61: Unrecognized content encoding type" 로 실패한다(실측).
            //    헤더로 identity 를 줘도 소용없고(옵션이 우선), 이 옵션이라야 평문으로 받는다.
            $resp = Http::withOptions(['decode_content' => false])
                ->timeout((int) config('rankfree.newbiz.timeout', 20))->get($url);
            if (! $resp->successful()) {
                return ['total' => 0, 'rows' => [], 'error' => 'HTTP '.$resp->status()];
            }
            // 오류는 XML 로 오기도 한다(sample 키 범위 초과 등) — JSON 파싱 전에 잡는다
            $body = ltrim((string) $resp->body());
            if (str_starts_with($body, '<')) {
                preg_match('#<CODE>(.*?)</CODE>#s', $body, $c);
                preg_match('#<MESSAGE>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?</MESSAGE>#s', $body, $m);

                return ['total' => 0, 'rows' => [], 'error' => trim(($c[1] ?? 'ERROR').' '.($m[1] ?? ''))];
            }

            $json = $resp->json();
            // 데이터 없음(INFO-200) 은 정상 — 그날 인허가가 없을 뿐
            if (isset($json['RESULT'])) {
                $code = (string) ($json['RESULT']['CODE'] ?? '');

                return ['total' => 0, 'rows' => [], 'error' => $code === 'INFO-200' ? null : ($json['RESULT']['MESSAGE'] ?? $code)];
            }
            $body = $json[$svc] ?? null;
            if (! is_array($body)) {
                return ['total' => 0, 'rows' => [], 'error' => '응답 구조 변경(서비스명 키 없음)'];
            }
            $code = (string) ($body['RESULT']['CODE'] ?? '');
            if ($code !== '' && $code !== 'INFO-000') {
                return ['total' => 0, 'rows' => [], 'error' => $body['RESULT']['MESSAGE'] ?? $code];
            }

            return [
                'total' => (int) ($body['list_total_count'] ?? 0),
                'rows' => array_values(array_filter((array) ($body['row'] ?? []), 'is_array')),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('newbiz: seoul api failed', ['svc' => $svc, 'date' => $dateFilter, 'msg' => $e->getMessage()]);

            return ['total' => 0, 'rows' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * 인증키 — 어드민 환경 설정(app_settings: seoul.openapi_key)이 .env 를 오버라이드한다
     * (SettingsServiceProvider). 비어 있으면 sample(일자당 5건 제한).
     */
    public function key(): string
    {
        return trim((string) config('rankfree.newbiz.seoul_key', '')) ?: 'sample';
    }

    /** sample 키는 1~5 범위만 허용(ERROR-335) — 페이지 크기를 낮추고 페이지네이션도 막는다. */
    public function pageSize(): int
    {
        return $this->isSampleKey() ? 5 : max(1, min(1000, (int) config('rankfree.newbiz.page_size', 1000)));
    }

    /** sample 키 여부 — true 면 하루치 5건까지만 받을 수 있다(정식 키 발급 필요). */
    public function isSampleKey(): bool
    {
        return $this->key() === 'sample';
    }
}
