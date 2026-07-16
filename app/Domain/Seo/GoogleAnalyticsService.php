<?php

namespace App\Domain\Seo;

use App\Models\AppSetting;
use App\Models\GaStat;
use App\Support\GoogleServiceAccount;
use Illuminate\Support\Facades\Http;

/**
 * GA4 방문 통계 수집 — Analytics Data API(runReport).
 * 같은 서비스 계정(GOOGLE_SERVICE_ACCOUNT_JSON)을 GA4 속성에 뷰어로 추가해야 한다.
 * 속성 ID(숫자)는 환경설정(ga.property_id)에서 관리.
 */
class GoogleAnalyticsService
{
    private const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

    public static function propertyId(): string
    {
        return trim((string) AppSetting::read('ga.property_id'));
    }

    public static function configured(): bool
    {
        return \App\Support\GoogleToken::available() && self::propertyId() !== '';
    }

    /** 최근 N일 수집·적재. 반환: [ok, message, rows]. */
    public function collect(int $days = 7): array
    {
        $pid = self::propertyId();
        if ($pid === '') {
            return ['ok' => false, 'rows' => 0, 'message' => 'GA4 속성 ID가 설정되지 않았습니다 — 환경설정 › 외부 연동에서 등록하세요.'];
        }
        $token = \App\Support\GoogleToken::token(self::SCOPE);
        if (! $token) {
            return ['ok' => false, 'rows' => 0, 'message' => '구글 인증 실패 — 환경설정 › 외부 연동에서 [구글 계정으로 연동]을 하거나 서비스 계정 키를 설정하세요.'];
        }

        $end = now('Asia/Seoul')->subDay()->toDateString();     // 어제까지(당일은 미확정)
        $start = now('Asia/Seoul')->subDays($days)->toDateString();
        $rows = 0;

        $reports = [
            'date' => ['dimensions' => ['date'], 'metrics' => ['activeUsers', 'newUsers', 'sessions', 'screenPageViews'], 'limit' => 500],
            'channel' => ['dimensions' => ['date', 'sessionDefaultChannelGroup'], 'metrics' => ['activeUsers', 'sessions'], 'limit' => 2000],
            'source' => ['dimensions' => ['date', 'sessionSource'], 'metrics' => ['activeUsers', 'sessions'], 'limit' => 2000],
            'page' => ['dimensions' => ['date', 'pagePath'], 'metrics' => ['activeUsers', 'screenPageViews'], 'limit' => 2000],
        ];

        foreach ($reports as $dim => $def) {
            $res = Http::timeout(30)->withToken($token)->post(
                "https://analyticsdata.googleapis.com/v1beta/properties/{$pid}:runReport",
                [
                    'dateRanges' => [['startDate' => $start, 'endDate' => $end]],
                    'dimensions' => array_map(fn ($d) => ['name' => $d], $def['dimensions']),
                    'metrics' => array_map(fn ($m) => ['name' => $m], $def['metrics']),
                    'limit' => $def['limit'],
                ],
            );
            if (! $res->successful()) {
                return ['ok' => false, 'rows' => $rows, 'message' => "API 오류({$dim}) HTTP {$res->status()} — ".mb_substr($res->body(), 0, 300)];
            }

            foreach ((array) $res->json('rows', []) as $r) {
                $dims = array_map(fn ($d) => (string) ($d['value'] ?? ''), (array) ($r['dimensionValues'] ?? []));
                $mets = array_map(fn ($m) => (int) ($m['value'] ?? 0), (array) ($r['metricValues'] ?? []));
                $date = preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', $dims[0] ?? $end);

                $data = ['users' => $mets[0] ?? 0];
                if ($dim === 'date') {
                    $data += ['new_users' => $mets[1] ?? 0, 'sessions' => $mets[2] ?? 0, 'pageviews' => $mets[3] ?? 0];
                } elseif ($dim === 'page') {
                    $data += ['pageviews' => $mets[1] ?? 0];
                } else {
                    $data += ['sessions' => $mets[1] ?? 0];
                }

                GaStat::updateOrCreate(
                    [
                        // 날짜는 Carbon 으로 — 문자열로 넘기면 저장 형식('Y-m-d 00:00:00')과 달라 재수집 시 유니크 충돌
                        'date' => \Illuminate\Support\Carbon::parse($date)->startOfDay(),
                        'dimension' => $dim,
                        'value' => $dim === 'date' ? '' : mb_substr($dims[1] ?? '', 0, 500),
                    ],
                    $data,
                );
                $rows++;
            }
        }

        return ['ok' => true, 'rows' => $rows, 'message' => "{$start} ~ {$end} 수집 완료 — {$rows}행 갱신"];
    }
}
