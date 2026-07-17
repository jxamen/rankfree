<?php

namespace Jcurve\Ga4Insights;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Jcurve\Ga4Insights\Contracts\Ga4Credentials;

/**
 * GA4 상세 리포트 빌더 — 한 번의 조회로 대시보드 전 섹션을 만든다.
 * 개요(기간 대비)·추이·유입(채널/소스매체/캠페인)·랜딩·인기페이지·이탈·기기·브라우저·지역·이벤트·신규재방문·시간대·실시간.
 */
class Ga4Reporter
{
    public function __construct(private Ga4Client $client, private Ga4Credentials $creds) {}

    public function isConfigured(): bool
    {
        return $this->creds->isConfigured();
    }

    /** 기간(일) 대시보드 데이터. 실패 시 ['error'=>메시지]. */
    public function report(int $days): array
    {
        $days = max(1, min(365, $days));
        $ttl = (int) config('ga4-insights.cache_ttl', 600);
        $key = $this->cacheKey($days);

        $build = fn () => $this->build($days);

        return $ttl > 0 ? Cache::remember($key, $ttl, $build) : $build();
    }

    public function cacheKey(int $days): string
    {
        return 'ga4-insights:'.md5((string) $this->creds->propertyId()).':'.$days;
    }

    public function flush(int $days): void
    {
        Cache::forget($this->cacheKey($days));
    }

    private function build(int $days): array
    {
        $tz = config('ga4-insights.timezone', 'Asia/Seoul');
        $rowsN = (int) config('ga4-insights.rows', 15);
        $end = Carbon::now($tz)->subDay()->toDateString();
        $start = Carbon::now($tz)->subDays($days)->toDateString();
        $prevEnd = Carbon::now($tz)->subDays($days + 1)->toDateString();
        $prevStart = Carbon::now($tz)->subDays($days * 2)->toDateString();
        $cur = [['startDate' => $start, 'endDate' => $end]];
        $range = [
            'start' => $start, 'end' => $end, 'days' => $days,
            'prevStart' => $prevStart, 'prevEnd' => $prevEnd,
        ];

        $kpiMetrics = ['totalUsers', 'newUsers', 'sessions', 'screenPageViews', 'engagementRate',
            'averageSessionDuration', 'bounceRate', 'screenPageViewsPerSession', 'eventCount', 'keyEvents'];

        // 요청 정의 — 순서 고정(응답을 순서로 매칭)
        $requests = [
            // 0) 개요 KPI (현재+이전 기간 동시)
            $this->q([], $kpiMetrics, [['startDate' => $start, 'endDate' => $end], ['startDate' => $prevStart, 'endDate' => $prevEnd]]),
            // 1) 일별 추이
            $this->q(['date'], ['activeUsers', 'sessions', 'screenPageViews', 'engagementRate'], $cur, orderDim: 'date', desc: false, limit: 200),
            // 2) 유입 채널
            $this->q(['sessionDefaultChannelGroup'], ['sessions', 'totalUsers', 'engagementRate'], $cur, orderMetric: 'sessions', limit: 12),
            // 3) 소스/매체
            $this->q(['sessionSourceMedium'], ['sessions', 'totalUsers', 'engagementRate'], $cur, orderMetric: 'sessions', limit: $rowsN),
            // 4) 캠페인
            $this->q(['sessionCampaignName'], ['sessions', 'totalUsers'], $cur, orderMetric: 'sessions', limit: $rowsN),
            // 5) 랜딩(유입) 페이지
            $this->q(['landingPagePlusQueryString'], ['sessions', 'engagementRate', 'bounceRate', 'keyEvents'], $cur, orderMetric: 'sessions', limit: $rowsN),
            // 6) 인기 페이지
            $this->q(['pagePath'], ['screenPageViews', 'totalUsers', 'userEngagementDuration'], $cur, orderMetric: 'screenPageViews', limit: $rowsN),
            // 7) 기기
            $this->q(['deviceCategory'], ['sessions', 'totalUsers', 'engagementRate'], $cur, orderMetric: 'sessions', limit: 6),
            // 8) 브라우저
            $this->q(['browser'], ['sessions', 'totalUsers'], $cur, orderMetric: 'sessions', limit: 8),
            // 9) 국가
            $this->q(['country'], ['sessions', 'totalUsers'], $cur, orderMetric: 'sessions', limit: 8),
            // 10) 도시
            $this->q(['city'], ['sessions', 'totalUsers'], $cur, orderMetric: 'sessions', limit: $rowsN),
            // 11) 이벤트
            $this->q(['eventName'], ['eventCount', 'totalUsers'], $cur, orderMetric: 'eventCount', limit: $rowsN),
            // 12) 신규 vs 재방문
            $this->q(['newVsReturning'], ['activeUsers', 'sessions', 'engagementRate'], $cur, orderMetric: 'sessions', limit: 4),
            // 13) 시간대(시각)
            $this->q(['hour'], ['sessions', 'activeUsers'], $cur, orderDim: 'hour', desc: false, limit: 24),
        ];

        try {
            $reports = $this->client->batch($requests);
        } catch (Ga4Exception $e) {
            return ['error' => $e->getMessage(), 'range' => $range];
        }

        $r = fn (int $i) => $this->rows($reports[$i] ?? []);

        // KPI: dateRange 차원으로 현재/이전 분리
        [$curK, $prevK] = $this->splitKpi($r(0), $kpiMetrics);

        $data = [
            'range' => $range,
            'kpis' => $this->kpiCards($curK, $prevK),
            'trend' => $this->fillTrend($r(1), $start, $end),
            'channels' => $this->named($r(2), 'sessionDefaultChannelGroup', ['sessions', 'totalUsers', 'engagementRate']),
            'sourceMedium' => $this->named($r(3), 'sessionSourceMedium', ['sessions', 'totalUsers', 'engagementRate']),
            'campaigns' => $this->named($r(4), 'sessionCampaignName', ['sessions', 'totalUsers']),
            'landing' => $this->named($r(5), 'landingPagePlusQueryString', ['sessions', 'engagementRate', 'bounceRate', 'keyEvents']),
            'pages' => $this->named($r(6), 'pagePath', ['screenPageViews', 'totalUsers', 'userEngagementDuration']),
            'devices' => $this->named($r(7), 'deviceCategory', ['sessions', 'totalUsers', 'engagementRate']),
            'browsers' => $this->named($r(8), 'browser', ['sessions', 'totalUsers']),
            'countries' => $this->named($r(9), 'country', ['sessions', 'totalUsers']),
            'cities' => $this->named($r(10), 'city', ['sessions', 'totalUsers']),
            'events' => $this->named($r(11), 'eventName', ['eventCount', 'totalUsers']),
            'newReturning' => $this->named($r(12), 'newVsReturning', ['activeUsers', 'sessions', 'engagementRate']),
            'hours' => $this->hours($r(13)),
            'realtime' => $this->realtime(),
            'error' => null,
        ];

        // 이탈 위험 = 랜딩 중 세션 충분 + 이탈률 높은 순
        $data['dropoff'] = collect($data['landing'])
            ->filter(fn ($x) => $x['sessions'] >= 2)
            ->sortByDesc('bounceRate')->take(10)->values()->all();

        return $data;
    }

    // ── 요청 빌더 ─────────────────────────────────────────────────────
    private function q(array $dims, array $metrics, array $dateRanges, ?string $orderMetric = null, ?string $orderDim = null, bool $desc = true, int $limit = 100): array
    {
        $req = [
            'dateRanges' => $dateRanges,
            'metrics' => array_map(fn ($m) => ['name' => $m], $metrics),
            'limit' => $limit,
            'keepEmptyRows' => false,
        ];
        if ($dims) {
            $req['dimensions'] = array_map(fn ($d) => ['name' => $d], $dims);
        }
        if ($orderMetric) {
            $req['orderBys'] = [['metric' => ['metricName' => $orderMetric], 'desc' => $desc]];
        } elseif ($orderDim) {
            $req['orderBys'] = [['dimension' => ['dimensionName' => $orderDim], 'desc' => $desc]];
        }

        return $req;
    }

    // ── 응답 파싱 ─────────────────────────────────────────────────────
    /** report → [ ['d'=>[dim=>val], 'm'=>[metric=>val]], … ] */
    private function rows(array $report): array
    {
        $dh = array_map(fn ($h) => $h['name'] ?? '', $report['dimensionHeaders'] ?? []);
        $mh = array_map(fn ($h) => $h['name'] ?? '', $report['metricHeaders'] ?? []);
        $out = [];
        foreach ($report['rows'] ?? [] as $row) {
            $d = [];
            foreach ($row['dimensionValues'] ?? [] as $i => $dv) {
                $d[$dh[$i] ?? $i] = (string) ($dv['value'] ?? '');
            }
            $m = [];
            foreach ($row['metricValues'] ?? [] as $i => $mv) {
                $m[$mh[$i] ?? $i] = (string) ($mv['value'] ?? '0');
            }
            $out[] = ['d' => $d, 'm' => $m];
        }

        return $out;
    }

    /** 다중 dateRange KPI → [현재, 이전] 각 metric=>float. */
    private function splitKpi(array $rows, array $metrics): array
    {
        $cur = $prev = array_fill_keys($metrics, 0.0);
        foreach ($rows as $row) {
            $isPrev = str_contains(implode('', $row['d']), 'date_range_1');
            $vals = [];
            foreach ($metrics as $mName) {
                $vals[$mName] = (float) ($row['m'][$mName] ?? 0);
            }
            if ($isPrev) {
                $prev = $vals;
            } else {
                $cur = $vals;
            }
        }

        return [$cur, $prev];
    }

    /** 차원 리스트 → 표준 행: name + metric들(라벨). */
    private function named(array $rows, string $dim, array $metrics): array
    {
        return array_map(function ($x) use ($dim, $metrics) {
            $out = ['name' => $this->label($x['d'][$dim] ?? '')];
            foreach ($metrics as $m) {
                $out[$this->alias($m)] = $this->isRatio($m) ? (float) ($x['m'][$m] ?? 0) : (int) round((float) ($x['m'][$m] ?? 0));
            }

            return $out;
        }, $rows);
    }

    /** 일별 추이 — 데이터 없는 날짜를 0으로 채워 연속 시계열로(막대 차트가 끊기지 않게). */
    private function fillTrend(array $rows, string $start, string $end): array
    {
        $map = [];
        foreach ($rows as $x) {
            $map[$this->ymd($x['d']['date'] ?? '')] = $x['m'];
        }
        $out = [];
        $cursor = Carbon::parse($start);
        $endC = Carbon::parse($end);
        while ($cursor <= $endC) {
            $ds = $cursor->toDateString();
            $m = $map[$ds] ?? [];
            $out[] = [
                'date' => $ds,
                'users' => (int) ($m['activeUsers'] ?? 0),
                'sessions' => (int) ($m['sessions'] ?? 0),
                'views' => (int) ($m['screenPageViews'] ?? 0),
                'engRate' => (float) ($m['engagementRate'] ?? 0),
            ];
            $cursor->addDay();
        }

        return $out;
    }

    private function hours(array $rows): array
    {
        $h = array_fill(0, 24, 0);
        foreach ($rows as $x) {
            $hr = (int) ($x['d']['hour'] ?? 0);
            if ($hr >= 0 && $hr < 24) {
                $h[$hr] = (int) round((float) ($x['m']['sessions'] ?? 0));
            }
        }

        return $h;
    }

    private function realtime(): array
    {
        $rep = $this->client->realtime([
            'dimensions' => [['name' => 'unifiedScreenName']],
            'metrics' => [['name' => 'activeUsers']],
            'orderBys' => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]],
            'limit' => 10,
        ]);
        $rows = $this->rows($rep);
        $total = 0;
        $pages = [];
        foreach ($rows as $x) {
            $n = (int) round((float) ($x['m']['activeUsers'] ?? 0));
            $total += $n;
            $pages[] = ['name' => $this->label($x['d']['unifiedScreenName'] ?? ''), 'users' => $n];
        }

        return ['activeUsers' => $total, 'pages' => $pages];
    }

    // ── KPI 카드 정의 ─────────────────────────────────────────────────
    private function kpiCards(array $cur, array $prev): array
    {
        $def = [
            ['totalUsers', '사용자', '기간 내 방문한 순 사용자 수', 'int', true],
            ['newUsers', '신규 사용자', '처음 방문한 사용자', 'int', true],
            ['sessions', '세션', '방문 횟수(30분 무활동 시 종료)', 'int', true],
            ['screenPageViews', '페이지뷰', '조회된 페이지의 총합', 'int', true],
            ['engagementRate', '참여율', '의미 있게 머문 세션 비율(10초+·2페이지+·전환)', 'pct', true],
            ['averageSessionDuration', '평균 방문 시간', '한 세션에 머문 평균 시간', 'duration', true],
            ['bounceRate', '이탈률', '바로 나간(참여 없는) 세션 비율 — 낮을수록 좋음', 'pct', false],
            ['screenPageViewsPerSession', '세션당 페이지뷰', '한 번 방문에 본 평균 페이지 수', 'num1', true],
            ['eventCount', '이벤트', '클릭·스크롤 등 상호작용 총 횟수', 'int', true],
            ['keyEvents', '주요 이벤트(전환)', '전환으로 표시한 핵심 행동 수', 'int', true],
        ];

        return array_map(fn ($d) => [
            'key' => $d[0], 'label' => $d[1], 'help' => $d[2], 'format' => $d[3], 'goodUp' => $d[4],
            'value' => $cur[$d[0]] ?? 0, 'prev' => $prev[$d[0]] ?? 0,
        ], $def);
    }

    // ── 라벨/유틸 ─────────────────────────────────────────────────────
    private function label(string $v): string
    {
        return match ($v) {
            '', '(not set)' => '미지정',
            '(direct)' => '직접 유입',
            '(none)' => '없음',
            '(organic)' => '자연 검색',
            'new' => '신규 방문',
            'returning' => '재방문',
            default => $v,
        };
    }

    private function alias(string $metric): string
    {
        return match ($metric) {
            'totalUsers', 'activeUsers' => 'users',
            'screenPageViews' => 'views',
            'engagementRate' => 'engRate',
            'bounceRate' => 'bounceRate',
            'userEngagementDuration' => 'engageSec',
            'keyEvents' => 'keyEvents',
            'eventCount' => 'events',
            default => $metric,
        };
    }

    private function isRatio(string $metric): bool
    {
        return in_array($metric, ['engagementRate', 'bounceRate'], true);
    }

    private function ymd(string $v): string
    {
        return preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', $v) ?: $v;
    }
}
