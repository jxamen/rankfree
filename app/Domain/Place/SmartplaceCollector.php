<?php

namespace App\Domain\Place;

/**
 * 네이버 스마트플레이스 리포트 수집기 — crm ads/smartplace/smartplace.lib.php 이식.
 * ─────────────────────────────────────────────────────────────────────────
 * [인증] 광고주가 스마트플레이스에 로그인한 브라우저의 cookie 헤더(NID_AUT/NID_SES 등)를 시드.
 *        통계용 Bearer(ba_access_token)는 NID 쿠키로 bizadvisor OAuth 리다이렉트를 따라가 자동 발급.
 * [소스] 통계(bizadvisor 6종) · 방문자/블로그 리뷰 · 예약고객 · 스마트콜.
 * [ID]   placeSeq 만으로 refined-businesses 가 placeId/siteId/businessId/업체명 제공.
 * 응답 구조는 네이버 변경 시 깨질 수 있음 — research-crm-smartplace-inventory.md 참조.
 */
class SmartplaceCollector
{
    public const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36';

    /** 스마트플레이스 URL → placeSeq / bookingBusinessId */
    public static function parseUrl(string $url): array
    {
        $ps = '';
        $bi = '';
        if (preg_match('#/place/(\d+)#', $url, $m)) {
            $ps = $m[1];
        }
        if (preg_match('#bookingBusinessId=(\d+)#', $url, $m)) {
            $bi = $m[1];
        }

        return ['place_seq' => $ps, 'business_id' => $bi];
    }

    /** 쿠키 헤더 문자열 → [name => value] */
    public static function parseCookie(string $str): array
    {
        $out = [];
        foreach (explode(';', $str) as $p) {
            $p = trim($p);
            if ($p === '' || ! str_contains($p, '=')) {
                continue;
            }
            $i = strpos($p, '=');
            $out[trim(substr($p, 0, $i))] = substr($p, $i + 1);
        }

        return $out;
    }

    /** 범용 curl — 네이버가 sec-fetch/sec-ch-ua 헤더를 검사하므로 브라우저와 동일하게 위장. */
    protected function http(string $url, array $opt = []): array
    {
        $ch = curl_init();
        $base = [
            'sec-ch-ua: "Google Chrome";v="149", "Chromium";v="149", "Not)A;Brand";v="24"',
            'sec-ch-ua-mobile: ?0', 'sec-ch-ua-platform: "Windows"',
            'sec-fetch-dest: empty', 'sec-fetch-mode: cors',
            'sec-fetch-site: '.($opt['site'] ?? 'same-origin'),
        ];
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($opt['method'] ?? 'GET'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => ! empty($opt['follow']),
            CURLOPT_MAXREDIRS => 12,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => array_merge($base, $opt['headers'] ?? []),
        ]);
        if (($opt['cookie'] ?? '') !== '') {
            curl_setopt($ch, CURLOPT_COOKIE, $opt['cookie']);
        }
        if (! empty($opt['jar'])) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $opt['jar']);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $opt['jar']);
        }
        if (isset($opt['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opt['body']);
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $code, 'body' => (string) $raw];
    }

    /**
     * 통계용 Bearer(ba_access_token) 발급 — NID 쿠키로 bizadvisor OAuth 리다이렉트 추적.
     * 쿠키에 ba_access_token 이 이미 있으면 그대로 사용.
     */
    public function baToken(string $cookieStr, string $placeSeq = ''): string
    {
        $pairs = self::parseCookie($cookieStr);
        if (! empty($pairs['ba_access_token'])) {
            return urldecode($pairs['ba_access_token']);
        }
        $jar = tempnam(sys_get_temp_dir(), 'spba');
        $ref = $placeSeq !== ''
            ? "https://new.smartplace.naver.com/bizes/place/{$placeSeq}/statistics?menu=place"
            : 'https://new.smartplace.naver.com/';
        $this->http('https://bizadvisor.naver.com/auth/naver/from/smartplace', [
            'follow' => true, 'cookie' => $cookieStr, 'jar' => $jar,
            'headers' => ['Referer: '.$ref],
        ]);
        $ba = '';
        if (is_file($jar)) {
            foreach (file($jar) as $line) {
                if (str_contains($line, 'ba_access_token')) {
                    $c = preg_split('/\s+/', trim($line));
                    $ba = end($c);
                }
            }
            @unlink($jar);
        }

        return $ba !== '' ? urldecode($ba) : '';
    }

    /**
     * 계정에 등록된 스마트플레이스 매장 목록 — /api/refined-businesses (place-seq 없이) 가 배열 반환.
     * 항목 구조가 문서화돼 있지 않아 placeSeq/업체명/placeId 를 재귀 탐색으로 방어적으로 추출한다.
     *
     * @return array{ok: bool, httpCode: int, businesses: array<int, array{placeSeq:string, name:string, placeId:string, businessId:string, raw:array}>}
     */
    public function listBusinesses(string $cookieStr): array
    {
        $r = $this->http('https://new.smartplace.naver.com/api/refined-businesses', [
            'cookie' => $cookieStr,
            'headers' => [
                'Accept: application/json, text/plain, */*', 'from-system: smartplace',
                'Referer: https://new.smartplace.naver.com/',
            ],
        ]);
        $j = json_decode($r['body'], true);
        // 배열(목록) 또는 {businesses:[...]} 등 래핑 모두 대응
        $list = [];
        if (is_array($j)) {
            $list = array_is_list($j) ? $j : ($j['businesses'] ?? $j['data'] ?? $j['list'] ?? []);
        }
        $out = [];
        foreach (is_array($list) ? $list : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $placeSeq = (string) ($this->deepFind($item, ['placeSeq', 'placeSeqNo']) ?? '');
            if ($placeSeq === '' || ! ctype_digit($placeSeq)) {
                continue; // placeSeq 없으면 URL 을 만들 수 없어 건너뜀
            }
            $sb = is_array($item['smartplaceBusiness'] ?? null) ? $item['smartplaceBusiness'] : [];
            $bb = is_array($item['bookingBusinesses'] ?? null) ? $item['bookingBusinesses'] : [];
            $out[] = [
                'placeSeq' => $placeSeq,
                'name' => (string) ($sb['name'] ?? $item['name'] ?? $item['businessName'] ?? $this->deepFind($item, ['name', 'businessName']) ?? ''),
                'placeId' => (string) ($sb['id'] ?? $item['placeId'] ?? $item['id'] ?? ''),
                'businessId' => (string) ($bb[0]['id'] ?? $item['businessId'] ?? ''),
                'raw' => $item,
            ];
        }

        return ['ok' => $r['code'] === 200, 'httpCode' => $r['code'], 'businesses' => $out];
    }

    /** 중첩 배열에서 주어진 키들 중 처음 발견되는 스칼라 값을 재귀 탐색(네이버 응답 키 위치 변동 방어). */
    private function deepFind(array $arr, array $keys): mixed
    {
        foreach ($keys as $k) {
            if (isset($arr[$k]) && is_scalar($arr[$k]) && (string) $arr[$k] !== '') {
                return $arr[$k];
            }
        }
        foreach ($arr as $v) {
            if (is_array($v)) {
                $found = $this->deepFind($v, $keys);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /** refined-businesses → placeId/siteId/businessId/name (업체마다 구조 다름). httpCode 401/403 = 세션 만료. */
    public function refined(string $cookieStr, string $placeSeq): array
    {
        $r = $this->http("https://new.smartplace.naver.com/api/refined-businesses/place-seq/{$placeSeq}", [
            'cookie' => $cookieStr,
            'headers' => [
                'Accept: application/json, text/plain, */*', 'from-system: smartplace',
                'Referer: https://new.smartplace.naver.com/bizes/place/'.$placeSeq,
            ],
        ]);
        $j = json_decode($r['body'], true);
        $ids = ['placeSeq' => $placeSeq, 'placeId' => '', 'siteId' => '', 'businessId' => '', 'name' => '', 'httpCode' => $r['code']];
        if (! is_array($j)) {
            return $ids;
        }
        $flat = json_encode($j, JSON_UNESCAPED_UNICODE);
        if (preg_match('/(sp_[a-f0-9]+)/', $flat, $m)) {
            $ids['siteId'] = $m[1];
        }
        $sb = is_array($j['smartplaceBusiness'] ?? null) ? $j['smartplaceBusiness'] : [];
        $bb = is_array($j['bookingBusinesses'] ?? null) ? $j['bookingBusinesses'] : [];
        $ids['placeId'] = (string) ($sb['id'] ?? $j['placeId'] ?? $j['id'] ?? '');
        $ids['name'] = (string) ($sb['name'] ?? $j['name'] ?? '');
        $ids['businessId'] = (string) ($bb[0]['id'] ?? $j['businessId'] ?? '');

        return $ids;
    }

    /** 통계 리포트 6종 정의 (bizadvisor) */
    public static function statReports(): array
    {
        return [
            ['key' => 'date_time', 'label' => '일자별 조회수', 'dimensions' => 'date_time', 'useIndex' => 'revenue-all-channel-detail'],
            ['key' => 'age_gender', 'label' => '연령·성별', 'dimensions' => 'age_bucket_all,gender', 'useIndex' => 'revenue-user-detail'],
            ['key' => 'hour', 'label' => '시간대별', 'dimensions' => 'hour_all', 'useIndex' => 'revenue-hour-detail'],
            ['key' => 'dow', 'label' => '요일별', 'dimensions' => 'day_of_week', 'useIndex' => 'revenue-hour-detail'],
            ['key' => 'channel', 'label' => '유입 채널', 'dimensions' => 'mapped_channel_name', 'useIndex' => 'revenue-all-channel-detail', 'sort' => 'pv'],
            ['key' => 'keyword', 'label' => '유입 검색어', 'dimensions' => 'ref_keyword', 'useIndex' => 'revenue-search-channel-detail', 'sort' => 'pv'],
        ];
    }

    /** 통계 report 1건 */
    public function report(string $cookieStr, string $bearer, string $siteId, array $rep, string $start, string $end): array
    {
        $qs = 'startDate='.$start.'&endDate='.$end.'&dimensions='.rawurlencode($rep['dimensions'])
            .'&metrics=pv&useIndex='.$rep['useIndex'].(isset($rep['sort']) ? '&sort='.$rep['sort'] : '');
        $r = $this->http("https://new.smartplace.naver.com/api/proxy/bizadvisor/api/v3/sites/{$siteId}/report?{$qs}", [
            'cookie' => $cookieStr,
            'headers' => [
                'Accept: application/json, text/plain, */*', 'from-system: smartplace',
                'authorization: Bearer '.$bearer, 'if-modified-since: Mon, 26 Jul 1997 05:00:00 GMT',
                'Referer: https://new.smartplace.naver.com/',
            ],
        ]);

        return ['status' => $r['code'], 'data' => json_decode($r['body'], true)];
    }

    /** 방문자 리뷰 (graphql getReviews) */
    public function reviews(string $cookieStr, array $ids): array
    {
        $query = 'fragment CommonReviewReplyFields on ReviewReply { text isSuspended isQualified createdDateTime updatedDateTime isDeleted useReplyCandidate replierDisplayName suspendPostingReason __typename } fragment CommonReviewFields on Review { author { displayName reviewCount imageCount profileImage visitCount userId __typename } placeDetail { id __typename } bookingDetail { bookingUserDetail business bizItem items __typename } content { text mediaItems { id type thumbnail url trailer metadata __typename } rating tags { votedKeywords { category keywords { code emojiCode emojiUrl label { ko __typename } __typename } __typename } __typename } textGradeInspection { grade __typename } __typename } reply { ...CommonReviewReplyFields __typename } reactionStat { id targetId totalCount sortedTypeCountEntries __typename } createdDateTime displayUpdatedDateTime id rating isSuspended suspendPostingReason isQualified source mainPov visitCount visitDateTime cp hasReply hasText hasVotedKeyword hasNegativeTextGrade __typename } query getReviews($input: GetReviewsInput!) { reviews(input: $input) { totalCount items { ...CommonReviewFields __typename } __typename } }';
        $body = json_encode([
            'operationName' => 'getReviews',
            'variables' => ['input' => ['sort' => 'CreatedDesc', 'placeId' => (string) $ids['placeId'], 'page' => 1]],
            'query' => $query,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $r = $this->http('https://new.smartplace.naver.com/graphql?opName=getReviews', [
            'method' => 'POST', 'cookie' => $cookieStr, 'body' => $body,
            'headers' => [
                'Accept: application/json, text/plain, */*', 'content-type: application/json', 'from-system: smartplace',
                'Origin: https://new.smartplace.naver.com',
                'Referer: https://new.smartplace.naver.com/bizes/place/'.$ids['placeSeq'].'/reviews?bookingBusinessId='.$ids['businessId'].'&menu=visitor',
            ],
        ]);

        return ['status' => $r['code'], 'data' => json_decode($r['body'], true)];
    }

    /** 블로그·카페 리뷰 — category(한글 업종) 비어 있으면 0건이므로 자동 판별과 세트로 사용. */
    public function blog(string $cookieStr, array $ids, string $category): array
    {
        $url = 'https://new.smartplace.naver.com/api/reviews/blog?businessId='.$ids['placeId'].'&category='.rawurlencode($category).'&page=1';
        $r = $this->http($url, [
            'cookie' => $cookieStr,
            'headers' => [
                'Accept: application/json, text/plain, */*', 'from-system: smartplace',
                'Referer: https://new.smartplace.naver.com/bizes/place/'.$ids['placeSeq'].'/reviews?menu=blog',
            ],
        ]);

        return ['status' => $r['code'], 'data' => json_decode($r['body'], true)];
    }

    /** m.place 상세에서 업종명(한글) 판별 — 블로그 리뷰 category 자동 채움용. */
    public function placeCategoryKr(string $placeId, string $cookie = ''): string
    {
        $placeId = preg_replace('/\D/', '', $placeId);
        if ($placeId === '') {
            return '';
        }
        $headers = [
            'accept: text/html,application/xhtml+xml', 'accept-language: ko-KR,ko;q=0.9',
            'sec-fetch-dest: document', 'sec-fetch-mode: navigate', 'upgrade-insecure-requests: 1',
        ];
        if (trim($cookie) !== '') {
            $headers[] = 'cookie: '.$cookie;
        }
        $r = $this->http('https://m.place.naver.com/place/'.$placeId.'/home', [
            'follow' => true, 'site' => 'none', 'headers' => $headers,
        ]);
        if (preg_match('/"__typename":"PlaceDetailBase".*?"category":"([^"]+)"/s', $r['body'], $m)) {
            return $m[1];
        }
        if (preg_match('/"category":"([^"]{1,20})"/', $r['body'], $m)) {
            return $m[1];
        }

        return '';
    }

    /** 예약/주문 고객 */
    public function bookingUsers(string $cookieStr, array $ids): array
    {
        if (($ids['businessId'] ?? '') === '') {
            return ['status' => 0, 'data' => null, 'skip' => '예약 미사용(businessId 없음)'];
        }
        // 고객분석(성별·연령·유입경로 분포) 집계를 위해 표본을 넉넉히(size=100) 가져온다
        $url = 'https://api-partner.booking.naver.com/v3.0/businesses/'.$ids['businessId'].'/users?page=0&searchValueCode=USER_NAME&size=100';
        $r = $this->http($url, [
            'cookie' => $cookieStr, 'site' => 'same-site',
            'headers' => [
                'Accept: application/json; charset=UTF-8', 'content-type: application/json; charset=UTF-8',
                'Origin: https://partner.booking.naver.com',
                'Referer: https://partner.booking.naver.com/bizes/'.$ids['businessId'].'/users',
                'x-booking-naver-role: OWNER',
            ],
        ]);

        return ['status' => $r['code'], 'data' => json_decode($r['body'], true)];
    }

    /** 스마트콜 발신자 */
    public function smartcallCallers(string $cookieStr, array $ids, string $start, string $end): array
    {
        $url = 'https://smartcall.smartplace.naver.com/api/businesses/'.$ids['placeId'].'/callers?end='.$end.'&limit=10&page=1&start='.$start;
        $r = $this->http($url, [
            'cookie' => $cookieStr,
            'headers' => ['Accept: application/json, text/plain, */*', 'Referer: https://smartcall.smartplace.naver.com/customers/'.$ids['placeId']],
        ]);

        return ['status' => $r['code'], 'data' => json_decode($r['body'], true)];
    }

    /** 스마트콜 통화수(오늘) */
    public function smartcallCount(string $cookieStr, array $ids): array
    {
        $now = now()->format('D M d Y H:i:s').' GMT+0900 (한국 표준시)';
        $sod = now()->format('D M d Y').' 00:00:00 GMT+0900 (한국 표준시)';
        $url = 'https://smartcall.smartplace.naver.com/api/businesses/'.$ids['placeId'].'/call-logs/count?end='.rawurlencode($now).'&start='.rawurlencode($sod);
        $r = $this->http($url, [
            'cookie' => $cookieStr,
            'headers' => ['Accept: application/json, text/plain, */*', 'Referer: https://smartcall.smartplace.naver.com/customers/'.$ids['placeId']],
        ]);

        return ['status' => $r['code'], 'data' => json_decode($r['body'], true)];
    }

    /**
     * 통합 수집.
     *
     * @param  string  $cookieStr  NID(+가능하면 ba_access_token) 쿠키 헤더
     * @param  string  $placeSeq   스마트플레이스 URL 의 place 숫자
     * @param  string  $category   업종(한글) — 비우면 m.place 상세로 자동 판별
     * @param  array|null  $period [startDate, endDate] (없으면 최근 7일)
     */
    public function collect(string $cookieStr, string $placeSeq, string $category = '', ?array $period = null): array
    {
        $start = ($period[0] ?? '') !== '' ? $period[0] : now()->subDays(6)->format('Y-m-d');
        $end = ($period[1] ?? '') !== '' ? $period[1] : now()->format('Y-m-d');

        $ids = $this->refined($cookieStr, $placeSeq);
        if (trim($category) === '' && $ids['placeId'] !== '') {
            $catKr = $this->placeCategoryKr($ids['placeId'], $cookieStr);
            if ($catKr !== '') {
                $category = $catKr;
            }
        }
        $bearer = $this->baToken($cookieStr, $placeSeq);
        $out = [
            'placeSeq' => $placeSeq, 'ids' => $ids, 'name' => $ids['name'], 'category' => $category,
            'period' => [$start, $end], 'bearerOk' => $bearer !== '',
            'loggedIn' => $ids['siteId'] !== '' || $ids['placeId'] !== '',
            'refinedCode' => (int) ($ids['httpCode'] ?? 0),
            'collectedAt' => now()->toIso8601String(), 'sections' => [],
        ];

        $stats = [];
        if ($ids['siteId'] !== '' && $bearer !== '') {
            foreach (self::statReports() as $rep) {
                $stats[$rep['key']] = ['label' => $rep['label']] + $this->report($cookieStr, $bearer, $ids['siteId'], $rep, $start, $end);
            }
        }
        $out['sections']['stats'] = $stats;
        if ($ids['placeId'] !== '') {
            $out['sections']['review_visitor'] = ['label' => '방문자 리뷰'] + $this->reviews($cookieStr, $ids);
            $out['sections']['review_blog'] = ['label' => '블로그 리뷰'] + $this->blog($cookieStr, $ids, $category);
            $out['sections']['smartcall_callers'] = ['label' => '스마트콜 발신자'] + $this->smartcallCallers($cookieStr, $ids, $start, $end);
            $out['sections']['smartcall_count'] = ['label' => '스마트콜 통화수'] + $this->smartcallCount($cookieStr, $ids);
        }
        $out['sections']['booking_users'] = ['label' => '예약/주문 고객'] + $this->bookingUsers($cookieStr, $ids);

        return $out;
    }
}
