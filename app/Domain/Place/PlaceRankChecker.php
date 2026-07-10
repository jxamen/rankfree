<?php

namespace App\Domain\Place;

/**
 * 네이버 플레이스 순위 체크 엔진 — crm smartplace.rank.lib.php 이식.
 *
 * 키워드로 pcmap-api graphql 을 최대 N페이지(50개씩) 순회하며 대상(placeId 또는 업체명)의 순위를 찾는다.
 *  - rank > 0        : 순위(리스트 내 위치)
 *  - rank == 0/300   : 순위 밖(N페이지 내 없음)
 *  - blocked == true : IP 차단/토큰 만료(-429) — 토큰 재발급 필요
 *
 * ⚠️ pcmap-api 는 nCaptcha 토큰(NcaptchaTokenStore) 없으면 405/429. 좌표는 서울 고정(PlaceCoordinates).
 */
class PlaceRankChecker
{
    private string $ua;

    public function __construct()
    {
        $this->ua = (string) config('rankfree.place.ua');
    }

    /**
     * 순위 조회.
     *
     * @param  string       $keyword    검색 키워드
     * @param  string|null  $placeId    대상 플레이스 ID(숫자). null 이면 $targetName 매칭
     * @param  string|null  $targetName 업체명(부분일치). $placeId 없을 때 사용
     * @param  string       $cat        카테고리 강제(payload 키). '' 이면 자동 판별
     * @param  string       $cookie     네이버 세션 쿠키(선택, 봇판정 완화)
     * @return array{blocked:bool,found:bool,rank:int,list_total:int,category:string,place_id:string,place_name:string,review_count:?int,blog_review_count:?int,save_count:?int,review_score:?float,tags:array}
     */
    public function check(string $keyword, ?string $placeId, ?string $targetName = null, string $cat = '', string $cookie = ''): array
    {
        $keyword = trim($keyword);
        $placeId = $placeId !== null ? preg_replace('/\D/', '', $placeId) : null;
        $targetName = $targetName !== null ? trim($targetName) : null;

        $result = [
            'blocked' => false, 'found' => false, 'rank' => 0, 'list_total' => 0,
            'category' => 'place', 'place_id' => (string) $placeId, 'place_name' => '',
            'review_count' => null, 'blog_review_count' => null, 'save_count' => null,
            'review_score' => null, 'tags' => [],
        ];

        if ($keyword === '' || ($placeId === '' && ($targetName === null || $targetName === ''))) {
            return $result;
        }

        // 토큰 없고 릴레이만 있으면 릴레이 위임(placeId 모드만 지원)
        $relay = (string) config('rankfree.place.relay_url');
        if (! NcaptchaTokenStore::has() && $relay !== '' && $placeId) {
            $rank = $this->rankViaRelay($keyword, $placeId);
            $result['rank'] = $rank;
            $result['found'] = $rank > 0 && $rank < 300;

            return $result;
        }

        // 카테고리 결정: 지정 → placeId 판별 → place
        $type = ($cat !== '' && in_array($cat, NaverPlacePayloads::categories(), true)) ? $cat : '';
        if ($type === '' && $placeId) {
            $type = $this->categoryByPid($placeId, $cookie);
        }
        if ($type === '') {
            $type = 'place';
        }
        $result['category'] = $type;

        $tmpl = NaverPlacePayloads::for($type);
        $isRest = ($type === 'restaurant' || $type === 'restaurants');

        $co = PlaceCoordinates::resolve($keyword);
        $maxPages = (int) config('rankfree.place.max_pages', 6);
        $delay = (int) config('rankfree.place.page_delay', 3);

        $rank = 0;
        $found = false;
        $listTotal = 0;

        for ($p = 1; $p <= $maxPages; $p++) {
            $start = (50 * ($p - 1)) + 1;
            $json = str_replace(
                ['{#start}', '{#page}', '{#query_encoding}', '{#query}', '{#x}', '{#y}', '{#bounds}', '{#reverseGeocodingInput_x}', '{#reverseGeocodingInput_y}'],
                [$start, $p, urlencode($keyword), $keyword, $co['x'], $co['y'], $co['bounds'], $co['reverseGeocodingInput']['x'], $co['reverseGeocodingInput']['y']],
                $tmpl,
            );

            $resp = $this->pcmapPost($json, $keyword, $type, $co['x'], $co['y'], $co['ts'], $cookie);

            if ($resp['code'] == 429) {
                $result['blocked'] = true;

                return $result;
            }
            if ($resp['code'] == 405) {
                sleep(3);
                $resp = $this->pcmapPost($json, $keyword, $type, $co['x'], $co['y'], $co['ts'], $cookie);
            }
            if ($resp['code'] == 429 || $resp['code'] == 405) {
                $result['blocked'] = true;

                return $result;
            }

            $data = $resp['data'];
            $bnode = $isRest
                ? ($data[0]['data']['restaurants']['businesses'] ?? [])
                : ($data[0]['data']['businesses'] ?? []);

            if (isset($bnode['total'])) {
                $listTotal = (int) $bnode['total'];
            }
            $items = (isset($bnode['items']) && is_array($bnode['items'])) ? $bnode['items'] : [];
            if (! count($items)) {
                break;
            }

            foreach ($items as $it) {
                $rank++;
                $match = $placeId
                    ? (isset($it['id']) && (string) $it['id'] === (string) $placeId)
                    : (isset($it['name']) && $targetName !== null && mb_stripos($it['name'], $targetName) !== false);

                if ($match) {
                    $found = true;
                    $result['place_id'] = (string) ($it['id'] ?? $placeId);
                    $result['place_name'] = (string) ($it['name'] ?? '');
                    $result['review_count'] = isset($it['visitorReviewCount']) ? (int) $it['visitorReviewCount'] : null;
                    $result['blog_review_count'] = isset($it['blogCafeReviewCount']) ? (int) $it['blogCafeReviewCount'] : null;
                    $result['save_count'] = isset($it['saveCount']) ? (int) $it['saveCount'] : null;
                    $result['review_score'] = isset($it['visitorReviewScore']) ? (float) $it['visitorReviewScore'] : null;
                    $result['tags'] = (isset($it['tags']) && is_array($it['tags'])) ? $it['tags'] : [];
                    break;
                }
            }

            if ($found) {
                break;
            }
            if ($p < $maxPages) {
                sleep($delay);
            }
        }

        $result['found'] = $found;
        $result['rank'] = $found ? $rank : 300;
        $result['list_total'] = $listTotal;

        // 비-restaurant 카테고리는 리스트 item에 평점/저장수/태그가 없음 → 상세 보강
        if ($found && ! $isRest && $result['review_score'] === null) {
            $detail = $this->placeDetailInfo($result['place_id'], $type, $cookie);
            $result['review_count'] = $result['review_count'] ?? $detail['visitorReviewCount'];
            $result['blog_review_count'] = $result['blog_review_count'] ?? $detail['blogCafeReviewCount'];
            $result['review_score'] = $detail['visitorReviewScore'];
            if ($detail['tags']) {
                $result['tags'] = $detail['tags'];
            }
            if ($result['place_name'] === '' && $detail['name'] !== '') {
                $result['place_name'] = $detail['name'];
            }
        }

        return $result;
    }

    /** pcmap-api graphql POST → ['data'=>decoded, 'code'=>http]. 헤더 위장 + nCaptcha 토큰. */
    private function pcmapPost(string $jsonData, string $keyword, string $type, string $x, string $y, string $ts, string $cookie = ''): array
    {
        $uparts = explode('Chrome/', $this->ua);
        $chrome = isset($uparts[1]) ? explode('.', $uparts[1])[0] : '134';

        $headers = [
            'Accept: */*', 'Accept-Language: ko', 'Content-Type: application/json',
            'Origin: https://pcmap.place.naver.com',
            'Referer: https://pcmap.place.naver.com/place/list?query=' . urlencode($keyword) . "&x={$x}&y={$y}&clientX={$x}&clientY={$y}&fromNxList=true&noredirect=1&entry=pll&ts={$ts}&mapUrl=https%3A%2F%2Fmap.naver.com%2Fp%2Fsearch%2F" . urlencode($keyword),
            "Sec-Ch-Ua: \"Not A(Brand\";v=\"8\", \"Google Chrome\";v=\"{$chrome}\", \"Chromium\";v=\"{$chrome}\"",
            'Sec-Ch-Ua-Mobile: ?0', 'Sec-Ch-Ua-Platform: "Windows"',
            'Sec-Fetch-Dest: empty', 'Sec-Fetch-Mode: cors', 'Sec-Fetch-Site: same-site',
            'User-Agent: ' . $this->ua,
            'X-Wtm-Graphql: ' . base64_encode(json_encode(['arg' => $keyword, 'type' => $type, 'source' => 'place'], JSON_UNESCAPED_UNICODE)),
        ];

        $ncap = NcaptchaTokenStore::get();
        $headers[] = ($ncap !== '') ? ('x-wtm-ncaptcha-token: ' . $ncap) : 'x-ncaptcha-violation: false';
        if (trim($cookie) !== '') {
            $headers[] = 'Cookie: ' . $cookie;
        }

        $ch = curl_init('https://pcmap-api.place.naver.com/graphql');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) config('rankfree.place.timeout', 20));
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['data' => json_decode((string) $resp, true), 'code' => $code];
    }

    /** placeId → 업종(payload 키). m.place place/{pid}/home 리다이렉트 + __APOLLO_STATE__ category. */
    public function categoryByPid(string $pid, string $cookie = ''): string
    {
        $pid = preg_replace('/\D/', '', $pid);
        if ($pid === '') {
            return 'place';
        }

        $ch = curl_init('https://m.place.naver.com/place/' . $pid . '/home');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) config('rankfree.place.timeout', 20));
        $h = [
            'accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'accept-language: ko-KR,ko;q=0.9', 'user-agent: ' . $this->ua,
            'sec-fetch-dest: document', 'sec-fetch-mode: navigate', 'sec-fetch-site: none',
            'upgrade-insecure-requests: 1',
        ];
        if (trim($cookie) !== '') {
            $h[] = 'cookie: ' . $cookie;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        $html = (string) curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        $p = $this->categoryFromString($info['url'] ?? '');
        if ($p !== 'place') {
            return $p;
        }
        if (preg_match('/"__typename":"PlaceDetailBase".*?"category":"([^"]+)"/s', $html, $m)) {
            return self::categoryKorToPath($m[1]);
        }
        if (preg_match('/"category":"([^"]+)"/', $html, $m)) {
            return self::categoryKorToPath($m[1]);
        }
        foreach (['hairshop', 'restaurant', 'hospital', 'nailshop', 'accommodation'] as $c) {
            if (strpos($html, '/' . $c . '/' . $pid) !== false) {
                return $c;
            }
        }

        return 'place';
    }

    /** m.place 상세에서 리뷰수·평점·태그 추출(저장수는 m.place 미노출→null). */
    public function placeDetailInfo(string $placeId, string $cat = 'place', string $cookie = ''): array
    {
        $placeId = preg_replace('/\D/', '', $placeId);
        $out = ['name' => '', 'category' => '', 'visitorReviewCount' => null, 'blogCafeReviewCount' => null, 'visitorReviewScore' => null, 'tags' => []];
        if ($placeId === '') {
            return $out;
        }
        $path = in_array($cat, ['hairshop', 'nailshop', 'hospital', 'restaurant', 'accommodation'], true) ? $cat : 'place';

        $ch = curl_init('https://m.place.naver.com/' . $path . '/' . $placeId . '/home');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) config('rankfree.place.timeout', 20));
        $h = ['accept: text/html', 'accept-language: ko-KR,ko;q=0.9', 'user-agent: ' . $this->ua, 'sec-fetch-dest: document', 'sec-fetch-mode: navigate', 'sec-fetch-site: none', 'upgrade-insecure-requests: 1'];
        if (trim($cookie) !== '') {
            $h[] = 'cookie: ' . $cookie;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        $html = (string) curl_exec($ch);
        curl_close($ch);
        if ($html === '') {
            return $out;
        }

        if (preg_match('/"PlaceDetailBase:' . $placeId . '".*?"name"\s*:\s*"([^"]*)"/s', $html, $m)) {
            $out['name'] = $m[1];
        }
        if (preg_match('/"PlaceDetailBase:' . $placeId . '".*?"category"\s*:\s*"([^"]*)"/s', $html, $m)) {
            $out['category'] = $m[1];
        }
        if (preg_match('/"visitorReviewsTotal"\s*:\s*(\d+)/', $html, $m)) {
            $out['visitorReviewCount'] = (int) $m[1];
        }
        if (preg_match('/"cafeBlogReviewsTotal"\s*:\s*(\d+)/', $html, $m)) {
            $out['blogCafeReviewCount'] = (int) $m[1];
        }
        if (preg_match('/"visitorReviewsScore"\s*:\s*([\d.]+)/', $html, $m)) {
            $out['visitorReviewScore'] = (float) $m[1];
        }
        if (preg_match('/"keywordList"\s*:\s*(\[[^\]]*\])/', $html, $m)) {
            $t = json_decode($m[1], true);
            if (is_array($t)) {
                $out['tags'] = $t;
            }
        }

        return $out;
    }

    /** 외부 순위 릴레이(안막히는 IP 서버) 위임. */
    private function rankViaRelay(string $keyword, string $pid): int
    {
        $relay = (string) config('rankfree.place.relay_url');
        $pid = preg_replace('/\D/', '', $pid);
        $url = $relay
            . (str_contains($relay, '?') ? '&' : '?')
            . 'action=get_place_rank&max_rank_page=6&keyword=' . urlencode($keyword) . '&keyword2='
            . '&url=https://m.place.naver.com/place/' . $pid;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code != 200) {
            return 300;
        }
        $j = json_decode((string) $resp, true);

        return (is_array($j) && isset($j['rank'])) ? (int) $j['rank'] : 300;
    }

    /** URL/문자열에서 업종 판별 */
    private function categoryFromString(string $s): string
    {
        foreach (['restaurant', 'hospital', 'hairshop', 'nailshop', 'accommodation'] as $c) {
            if (strpos($s, $c) !== false) {
                return $c;
            }
        }

        return 'place';
    }

    /** 네이버 업종명(한글) → m.place 경로 */
    public static function categoryKorToPath(string $cat): string
    {
        if ($cat === '') {
            return 'place';
        }
        if (str_contains($cat, '네일')) {
            return 'nailshop';
        }
        foreach (['미용', '헤어', '뷰티', '피부관리', '왁싱', '속눈썹', '태닝', '반영구', '메이크업'] as $k) {
            if (str_contains($cat, $k)) {
                return 'hairshop';
            }
        }
        foreach (['병원', '의원', '치과', '한의', '정형', '피부과', '성형', '클리닉'] as $k) {
            if (str_contains($cat, $k)) {
                return 'hospital';
            }
        }
        foreach (['숙박', '호텔', '모텔', '펜션', '게스트', '리조트'] as $k) {
            if (str_contains($cat, $k)) {
                return 'accommodation';
            }
        }
        foreach (['음식', '맛집', '식당', '한식', '일식', '중식', '양식', '분식', '카페', '고기', '치킨', '피자', '뷔페', '횟집', '국수', '찌개', '술집'] as $k) {
            if (str_contains($cat, $k)) {
                return 'restaurant';
            }
        }

        return 'place';
    }

    /**
     * 입력(URL/숫자)에서 placeId 추출. 못 찾으면 null.
     * crm sp_place_url_convert 3단 폴백: /place|업종/{digits} → 순수숫자 → URL이면 \d{6,} 덩어리.
     */
    public static function extractPlaceId(string $input): ?string
    {
        $input = trim($input);
        // 1) m.place / pcmap / map.naver 등의 URL 내 /{cat}/{digits}
        if (preg_match('#/(?:place|restaurant|hairshop|nailshop|hospital|accommodation)/(\d+)#', $input, $m)) {
            return $m[1];
        }
        // 2) 순수 숫자(ID 직접 입력)
        if (preg_match('/^\d{5,}$/', $input)) {
            return $input;
        }
        // 3) 네이버 URL 이면 마지막 폴백: 6자리 이상 숫자 덩어리
        if (preg_match('#^https?://#i', $input) || str_contains(strtolower($input), 'naver.')) {
            if (preg_match('#(\d{6,})#', $input, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * 입력(URL·단축URL·ID)에서 placeId 확정. naver.me 단축·map.naver 딥링크는 리다이렉트 최종 URL 에서 재추출.
     * crm 에는 없는 보강(사용자가 어떤 형태의 플레이스 URL 을 넣어도 자동 변환되도록).
     */
    public function resolvePlaceId(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        if ($pid = self::extractPlaceId($input)) {
            return $pid;
        }
        // http(s) URL 이면 최종 URL 따라가 재시도 (naver.me 단축, map.naver 리다이렉트)
        if (preg_match('#^https?://#i', $input)) {
            $final = $this->followFinalUrl($input);
            if ($final !== null && $final !== $input && ($pid = self::extractPlaceId($final))) {
                return $pid;
            }
        }

        return null;
    }

    /** 리다이렉트 최종 URL 반환. 최종 URL 에 pid 가 없으면 본문에서라도 m.place URL 재구성. */
    private function followFinalUrl(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 6);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) config('rankfree.place.timeout', 20));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'accept-language: ko-KR,ko;q=0.9', 'user-agent: ' . $this->ua,
        ]);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        $body = (string) curl_exec($ch);
        $final = (string) (curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: '');
        curl_close($ch);

        if (self::extractPlaceId($final) === null && $body !== '' && preg_match('#place[/:"](\d{5,})#', $body, $m)) {
            return 'https://m.place.naver.com/place/' . $m[1] . '/home';
        }

        return $final !== '' ? $final : null;
    }

    /**
     * placeId → ['name'=>업체명, 'category'=>경로키]. m.place /home SSR HTML 1회 조회.
     * crm sp_place_detail_info + sp_category_by_pid 를 1회 요청으로 합침.
     *  - 업체명: __APOLLO_STATE__ PlaceDetailBase:{pid}.name → og:title → <title> 폴백
     *  - 카테고리: 최종 리다이렉트 URL 경로 → apollo category(한글) → place
     */
    public function placeSummary(string $placeId, string $cookie = ''): array
    {
        $placeId = preg_replace('/\D/', '', $placeId);
        $out = ['name' => '', 'category' => 'place'];
        if ($placeId === '') {
            return $out;
        }

        $ch = curl_init('https://m.place.naver.com/place/' . $placeId . '/home');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) config('rankfree.place.timeout', 20));
        $h = [
            'accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'accept-language: ko-KR,ko;q=0.9', 'user-agent: ' . $this->ua,
            'sec-fetch-dest: document', 'sec-fetch-mode: navigate', 'sec-fetch-site: none',
            'upgrade-insecure-requests: 1',
        ];
        if (trim($cookie) !== '') {
            $h[] = 'cookie: ' . $cookie;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        $html = (string) curl_exec($ch);
        $finalUrl = (string) (curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: '');
        curl_close($ch);

        // 카테고리: 최종 URL 경로 → apollo category(한글)
        $cat = $this->categoryFromString($finalUrl);
        if ($cat === 'place') {
            if (preg_match('/"PlaceDetailBase:' . $placeId . '".*?"category"\s*:\s*"([^"]+)"/s', $html, $m)) {
                $cat = self::categoryKorToPath($m[1]);
            } elseif (preg_match('/"category"\s*:\s*"([^"]{1,20})"/', $html, $m)) {
                $cat = self::categoryKorToPath($m[1]);
            }
        }
        $out['category'] = $cat;

        // 업체명: PlaceDetailBase name → og:title → <title>
        if (preg_match('/"PlaceDetailBase:' . $placeId . '".*?"name"\s*:\s*"([^"]*)"/s', $html, $m) && $m[1] !== '') {
            $out['name'] = $m[1];
        } elseif (preg_match('#<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
            $out['name'] = $m[1];
        } elseif (preg_match('#<title>([^<]+)</title>#i', $html, $m)) {
            $out['name'] = preg_replace('/\s*[:|]\s*네이버.*$/u', '', $m[1]);
        }
        $out['name'] = trim(html_entity_decode((string) $out['name'], ENT_QUOTES, 'UTF-8'));

        return $out;
    }

    /**
     * 경쟁셋 수집(T1) — 키워드로 pcmap 상위 topN 리스트 + 내 순위 + 전체건수.
     * crm spa_serp_fetch 이식. nCaptcha 토큰 필요(없으면 blocked).
     *
     * @return array{blocked:bool, my_rank:int, total:int, items:list<array>}
     */
    public function serpFetch(string $keyword, string $cat, ?string $myPid, int $topN = 30, string $cookie = ''): array
    {
        $keyword = trim($keyword);
        $myPid = $myPid !== null ? preg_replace('/\D/', '', $myPid) : null;
        $type = ($cat !== '' && in_array($cat, NaverPlacePayloads::categories(), true)) ? $cat : 'place';
        $tmpl = NaverPlacePayloads::for($type);
        $isRest = ($type === 'restaurant' || $type === 'restaurants');

        $co = PlaceCoordinates::resolve($keyword);
        $maxPages = (int) config('rankfree.place.max_pages', 6);
        $delay = (int) config('rankfree.place.page_delay', 3);

        $items = [];
        $myRank = 0;
        $found = false;
        $total = 0;
        $rank = 0;

        for ($p = 1; $p <= $maxPages; $p++) {
            $start = (50 * ($p - 1)) + 1;
            $json = str_replace(
                ['{#start}', '{#page}', '{#query_encoding}', '{#query}', '{#x}', '{#y}', '{#bounds}', '{#reverseGeocodingInput_x}', '{#reverseGeocodingInput_y}'],
                [$start, $p, urlencode($keyword), $keyword, $co['x'], $co['y'], $co['bounds'], $co['reverseGeocodingInput']['x'], $co['reverseGeocodingInput']['y']],
                $tmpl,
            );
            $resp = $this->pcmapPost($json, $keyword, $type, $co['x'], $co['y'], $co['ts'], $cookie);
            if ($resp['code'] == 405) {
                sleep(3);
                $resp = $this->pcmapPost($json, $keyword, $type, $co['x'], $co['y'], $co['ts'], $cookie);
            }
            if ($resp['code'] == 429 || $resp['code'] == 405) {
                return ['blocked' => true, 'my_rank' => 300, 'total' => $total, 'items' => $items];
            }

            $data = $resp['data'];
            $bnode = $isRest
                ? ($data[0]['data']['restaurants']['businesses'] ?? [])
                : ($data[0]['data']['businesses'] ?? []);
            if (isset($bnode['total'])) {
                $total = (int) $bnode['total'];
            }
            $list = (isset($bnode['items']) && is_array($bnode['items'])) ? $bnode['items'] : [];
            if (! count($list)) {
                break;
            }
            foreach ($list as $it) {
                $rank++;
                if (count($items) < $topN) {
                    $items[] = self::serpItem($it, $rank);
                }
                if ($myPid && isset($it['id']) && (string) $it['id'] === (string) $myPid) {
                    $found = true;
                    $myRank = $rank;
                }
            }
            if ($found && count($items) >= $topN) {
                break;
            }
            if ($found && $rank >= $topN) {
                break;
            }
            if ($p < $maxPages) {
                sleep($delay);
            }
        }

        return ['blocked' => false, 'my_rank' => $found ? $myRank : 300, 'total' => $total, 'items' => $items];
    }

    /** pcmap item → serp 표준 필드. crm spa_serp_item. */
    private static function serpItem(array $it, int $rnk): array
    {
        $g = fn ($k) => $it[$k] ?? null;
        $num = fn ($v) => ($v === null || $v === '') ? null : (int) str_replace(',', '', (string) $v);
        $tags = $g('tags');
        if (! is_array($tags)) {
            $tags = [];
        }

        return [
            'rnk' => $rnk, 'place_id' => (string) $g('id'), 'name' => (string) $g('name'),
            'visitor_cnt' => $num($g('visitorReviewCount')), 'blog_cnt' => $num($g('blogCafeReviewCount')),
            'booking_cnt' => $num($g('bookingReviewCount')), 'save_cnt' => $num($g('saveCount')),
            'review_score' => ($g('visitorReviewScore') !== null && $g('visitorReviewScore') !== '') ? (float) $g('visitorReviewScore') : null,
            'tags' => $tags, 'address' => (string) ($g('address') ?: $g('roadAddress')),
        ];
    }

    /**
     * 상세 신호(T2) — m.place /home SSR HTML __APOLLO_STATE__ 전체 파싱.
     * crm spa_place_detail_full + spa_parse_detail_html 이식(로그인 불필요).
     */
    public function placeDetailFull(string $placeId, string $cat = 'place', string $cookie = ''): array
    {
        $pid = preg_replace('/\D/', '', $placeId);
        if ($pid === '') {
            return $this->parseDetailHtml('', $pid, $cat);
        }
        $path = in_array($cat, ['hairshop', 'nailshop', 'hospital', 'restaurant', 'accommodation'], true) ? $cat : 'place';

        $ch = curl_init('https://m.place.naver.com/' . $path . '/' . $pid . '/home');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) config('rankfree.place.timeout', 20));
        $h = ['accept: text/html', 'accept-language: ko-KR,ko;q=0.9', 'user-agent: ' . $this->ua, 'sec-fetch-dest: document', 'sec-fetch-mode: navigate', 'sec-fetch-site: none', 'upgrade-insecure-requests: 1'];
        if (trim($cookie) !== '') {
            $h[] = 'cookie: ' . $cookie;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        $html = (string) curl_exec($ch);
        curl_close($ch);

        return $this->parseDetailHtml($html, $pid, $cat);
    }

    /** __APOLLO_STATE__ → 상세 신호 배열. crm spa_parse_detail_html. */
    private function parseDetailHtml(string $html, string $pid, string $cat): array
    {
        $pid = preg_replace('/\D/', '', $pid);
        $out = [
            'name' => '', 'category' => '', 'category_cnt' => null, 'visitor_cnt' => null, 'review_score' => null,
            'blog_cnt' => null, 'text_review' => null, 'has_road' => null, 'conv_cnt' => null, 'pay_cnt' => null,
            'keyword_cnt' => null, 'menu_cnt' => null, 'photo_cnt' => null, 'stylist_cnt' => null,
            'hide_hours' => null, 'hide_price' => null, 'missing_cnt' => null, 'has_talktalk' => null, 'has_chatbot' => null, 'has_booking' => null,
            'place_plus' => null, 'review_kw' => null, 'missing_labels' => [], 'tags' => [], 'ok' => false,
        ];
        if ($html === '') {
            return $out;
        }

        $state = null;
        if (preg_match('/window\.__APOLLO_STATE__\s*=\s*(\{.*?\});/s', $html, $m)) {
            $state = json_decode($m[1], true);
        }
        if (is_array($state)) {
            $base = $state['PlaceDetailBase:' . $pid] ?? null;
            if (! $base) {
                foreach ($state as $k => $v) {
                    if (strpos($k, 'DetailBase:') !== false) {
                        $base = $v;
                        break;
                    }
                }
            }
            if (is_array($base)) {
                $out['name'] = $base['name'] ?? '';
                $out['category'] = $base['category'] ?? '';
                $out['category_cnt'] = isset($base['categoryCount']) ? (int) $base['categoryCount'] : null;
                $out['visitor_cnt'] = isset($base['visitorReviewsTotal']) ? (int) $base['visitorReviewsTotal'] : null;
                $out['review_score'] = (isset($base['visitorReviewsScore']) && $base['visitorReviewsScore'] > 0) ? (float) $base['visitorReviewsScore'] : null;
                $out['blog_cnt'] = isset($base['cafeBlogReviewsTotal']) ? (int) $base['cafeBlogReviewsTotal'] : null;
                $out['text_review'] = isset($base['visitorReviewsTextReviewTotal']) ? (int) $base['visitorReviewsTextReviewTotal'] : null;
                $out['has_road'] = (isset($base['road']) && trim((string) $base['road']) !== '') ? 1 : 0;
                $out['conv_cnt'] = (isset($base['conveniences']) && is_array($base['conveniences'])) ? count($base['conveniences']) : null;
                $out['pay_cnt'] = (isset($base['paymentInfo']) && is_array($base['paymentInfo'])) ? count($base['paymentInfo']) : null;
                $out['hide_hours'] = ! empty($base['hideBusinessHours']) ? 1 : 0;
                $out['hide_price'] = ! empty($base['hidePrice']) ? 1 : 0;
                $out['has_talktalk'] = (isset($base['talktalkUrl']) && $base['talktalkUrl']) ? 1 : 0;
                $out['has_chatbot'] = (isset($base['chatBotUrl']) && $base['chatBotUrl']) ? 1 : 0;
                // isBizHourMissing 은 false-positive 다수 → 제외(영업시간은 hide_hours 로 별도)
                $missMap = ['isMenuImageMissing' => '메뉴사진', 'isAccessorMissing' => '찾아오는길', 'isDescriptionMissing' => '소개글', 'isConveniencesMissing' => '편의시설'];
                $miss = [];
                if (isset($base['missingInfo']) && is_array($base['missingInfo'])) {
                    foreach ($missMap as $f => $lab) {
                        if (! empty($base['missingInfo'][$f])) {
                            $miss[] = $lab;
                        }
                    }
                }
                $out['missing_cnt'] = count($miss);
                $out['missing_labels'] = $miss;
            }
            // 노드 카운트
            $mc = 0;
            $pc = 0;
            $sc = 0;
            foreach ($state as $k => $v) {
                if (strpos($k, 'Menu:') === 0) {
                    $mc++;
                } elseif (strpos($k, 'PlaceDetailTopPhotoItem:') === 0) {
                    $pc++;
                } elseif (strpos($k, 'Stylist:') === 0) {
                    $sc++;
                }
            }
            $out['menu_cnt'] = $mc;
            $out['photo_cnt'] = $pc;
            $out['stylist_cnt'] = $sc;

            $flat = json_encode($state, JSON_UNESCAPED_UNICODE);
            if (preg_match('/"keywordList"\s*:\s*(\[[^\]]*\])/', $flat, $km)) {
                $t = json_decode($km[1], true);
                if (is_array($t)) {
                    $out['tags'] = $t;
                    $out['keyword_cnt'] = count($t);
                }
            }
            $out['has_booking'] = preg_match('/"bookingBusinessId"\s*:\s*"?\d/', $flat) ? 1 : 0;
            $out['place_plus'] = preg_match('/"key"\s*:\s*"placePlus"/', $flat) ? 1 : 0;

            // 리뷰 키워드(방문자 리뷰 AI 분석)
            $vrs = null;
            foreach ($state as $k => $v) {
                if (strpos($k, 'VisitorReviewStatsResult:') === 0) {
                    $vrs = $v;
                    break;
                }
            }
            if (is_array($vrs) && ! empty($vrs['analysis']) && is_array($vrs['analysis'])) {
                $an = $vrs['analysis'];
                $rk = ['menus' => [], 'themes' => [], 'voted' => []];
                if (! empty($an['menus']) && is_array($an['menus'])) {
                    foreach (array_slice($an['menus'], 0, 15) as $it) {
                        if (isset($it['label'])) {
                            $rk['menus'][] = ['l' => $it['label'], 'c' => (int) ($it['count'] ?? 0)];
                        }
                    }
                }
                if (! empty($an['themes']) && is_array($an['themes'])) {
                    foreach (array_slice($an['themes'], 0, 12) as $it) {
                        if (isset($it['label'])) {
                            $rk['themes'][] = ['l' => $it['label'], 'c' => (int) ($it['count'] ?? 0)];
                        }
                    }
                }
                if (! empty($an['votedKeyword']['details']) && is_array($an['votedKeyword']['details'])) {
                    foreach (array_slice($an['votedKeyword']['details'], 0, 12) as $it) {
                        if (isset($it['displayName'])) {
                            $rk['voted'][] = ['l' => $it['displayName'], 'c' => (int) ($it['count'] ?? 0)];
                        }
                    }
                }
                if ($rk['menus'] || $rk['themes'] || $rk['voted']) {
                    $out['review_kw'] = $rk;
                }
            }
            $out['ok'] = true;
        } else {
            if (preg_match('/"visitorReviewsTotal"\s*:\s*(\d+)/', $html, $mm)) {
                $out['visitor_cnt'] = (int) $mm[1];
            }
            if (preg_match('/"cafeBlogReviewsTotal"\s*:\s*(\d+)/', $html, $mm)) {
                $out['blog_cnt'] = (int) $mm[1];
            }
            if (preg_match('/"keywordList"\s*:\s*(\[[^\]]*\])/', $html, $mm)) {
                $t = json_decode($mm[1], true);
                if (is_array($t)) {
                    $out['tags'] = $t;
                    $out['keyword_cnt'] = count($t);
                }
            }
        }

        return $out;
    }
}
