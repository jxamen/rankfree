<?php

namespace App\Domain\Seo;

use App\Models\AppSetting;
use App\Models\GscStat;
use App\Support\GoogleServiceAccount;
use Illuminate\Support\Facades\Http;

/**
 * 구글 서치 콘솔 검색 성과 수집 — Search Analytics API.
 * 서비스 계정(.env GOOGLE_SERVICE_ACCOUNT_JSON)을 서치 콘솔 속성에 사용자로 추가해야 한다.
 * 데이터는 구글 측에서 2~3일 지연 반영되므로 수집은 3일 전 날짜까지를 갱신한다.
 */
class SearchConsoleService
{
    private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    /** 서치 콘솔 속성 — 설정(gsc.property) 우선, 기본 도메인 속성. */
    public static function property(): string
    {
        return AppSetting::read('gsc.property') ?: 'sc-domain:rankfree.kr';
    }

    public static function configured(): bool
    {
        return \App\Support\GoogleToken::available();
    }

    /**
     * 최근 N일 수집·적재. 반환: [ok, message, rows].
     * dimension: date(일 합계) + query/page/device(일별 상위).
     */
    public function collect(int $days = 7): array
    {
        $token = \App\Support\GoogleToken::token(self::SCOPE);
        if (! $token) {
            return ['ok' => false, 'rows' => 0, 'message' => '구글 인증 실패 — 환경설정 › 외부 연동에서 [구글 계정으로 연동]을 하거나 서비스 계정 키를 설정하세요.'];
        }

        $end = now('Asia/Seoul')->subDays(3)->toDateString();   // GSC 반영 지연 고려
        $start = now('Asia/Seoul')->subDays(2 + $days)->toDateString();
        $site = rawurlencode(self::property());
        $rows = 0;

        foreach (['date' => ['date'], 'query' => ['date', 'query'], 'page' => ['date', 'page'], 'device' => ['date', 'device']] as $dim => $dims) {
            $res = Http::timeout(30)->withToken($token)->post(
                "https://searchconsole.googleapis.com/webmasters/v3/sites/{$site}/searchAnalytics/query",
                [
                    'startDate' => $start,
                    'endDate' => $end,
                    'dimensions' => $dims,
                    'rowLimit' => $dim === 'date' ? 40 : 2000,
                    'dataState' => 'all',
                ],
            );
            if (! $res->successful()) {
                return ['ok' => false, 'rows' => $rows, 'message' => "API 오류({$dim}) HTTP {$res->status()} — ".mb_substr($res->body(), 0, 300)];
            }

            foreach ((array) $res->json('rows', []) as $r) {
                $keys = (array) ($r['keys'] ?? []);
                GscStat::updateOrCreate(
                    [
                        // 날짜는 Carbon 으로 — 문자열로 넘기면 저장 형식('Y-m-d 00:00:00')과 달라 재수집 시 유니크 충돌
                        'date' => \Illuminate\Support\Carbon::parse($keys[0] ?? $end)->startOfDay(),
                        'dimension' => $dim,
                        'value' => $dim === 'date' ? '' : mb_substr((string) ($keys[1] ?? ''), 0, 500),
                    ],
                    [
                        'clicks' => (int) ($r['clicks'] ?? 0),
                        'impressions' => (int) ($r['impressions'] ?? 0),
                        'ctr' => round((float) ($r['ctr'] ?? 0), 4),
                        'position' => round((float) ($r['position'] ?? 0), 2),
                    ],
                );
                $rows++;
            }
        }

        return ['ok' => true, 'rows' => $rows, 'message' => "{$start} ~ {$end} 수집 완료 — {$rows}행 갱신"];
    }
}
