<?php

namespace App\Domain\NewBiz;

use App\Models\NewBusiness;
use Illuminate\Support\Facades\Log;

/**
 * 신규 개업 업소의 **플레이스 등록 전화번호** 수집(24 2단계 보강).
 *
 * 왜 필요한가: 인허가 원천(SITETEL)은 대부분 비어 있다(실측 2026-07-17 — 신규 20건 중 5건만 채워짐).
 * 신고서에 전화 기재가 필수가 아니라 원천 자체가 결측이라, 번호를 보려면 플레이스에서 가져와야 한다.
 *
 * 경로(실측 2026-07-17, **브라우저 없이 PHP curl 로 전부 가능**):
 *  1) `pcmap.place.naver.com/place/list?query={지역 상호}` HTML → `window.__APOLLO_STATE__` 의
 *     `PlaceListBusinessesItem:{id}` 항목에 id·상호·주소·`phone`·`virtualPhone`·`hasMobilePhoneNumber` 가 **한 번에** 들어온다.
 *  2) `phone`(일반) → `virtualPhone`(안심번호 0507…) 순으로 쓴다.
 *  3) 둘 다 null 인데 `hasMobilePhoneNumber` 면 번호가 숨겨진 것 →
 *     `pcmap-api.place.naver.com/rest/phone?id={id}` 가 **base64** 로 `{"number":"010-…"}` 을 준다.
 *
 * ⚠️ 실측 근거(둘 중 하나만으로는 안 된다):
 *    - 풍납주먹고기(1965864491): virtualPhone=0507-1314-8662 인데 rest/phone 은 **404**
 *    - 메구미카페(2062089507): phone·virtualPhone 둘 다 null 인데 rest/phone 은 010-9636-2469
 * ⚠️ pcmap **GraphQL 순위 API** 는 상호 검색이 안 되지만(NewBusinessPlaceMatcher 주석 참조),
 *    **검색 페이지 HTML(place/list)** 은 상호로도 찾힌다 — 여기서 쓰는 건 후자다. 토큰·쿠키 불필요.
 * ⚠️ 수집한 번호는 광고 발송에 쓰지 않는다(정보통신망법 제50조) — 관리자 열람 전용. 저장은 암호화.
 */
class PlacePhoneFetcher
{
    /** @return array{place_id:string,phone:string,type:string}|null */
    public function fetch(NewBusiness $biz): ?array
    {
        $name = trim((string) $biz->bplc_nm);
        if ($name === '') {
            return null;
        }
        $region = trim((string) ($biz->emd ?: $biz->sgg ?: ''));

        $item = $this->pick($this->search(trim($region.' '.$name)), $biz)
            ?: $this->pick($this->search($name), $biz);   // 지역을 붙여 못 찾으면 상호만으로 재시도
        if (! $item) {
            return null;
        }

        $id = (string) ($item['id'] ?? '');
        $phone = trim((string) ($item['phone'] ?? ''));
        $virtual = trim((string) ($item['virtualPhone'] ?? ''));

        if ($phone !== '') {
            return ['place_id' => $id, 'phone' => $phone, 'type' => 'normal'];
        }
        if ($virtual !== '') {
            return ['place_id' => $id, 'phone' => $virtual, 'type' => 'virtual'];
        }
        // 목록에 번호가 없는데 있다고 표시된 경우 — 별도 API 에 base64 로 숨겨져 있다
        if (($item['hasMobilePhoneNumber'] ?? false) && $id !== '' && ($n = $this->restPhone($id)) !== null) {
            return ['place_id' => $id, 'phone' => $n, 'type' => 'normal'];
        }

        return ['place_id' => $id, 'phone' => '', 'type' => ''];   // 플레이스는 찾았지만 번호 비공개
    }

    /** 검색 결과 항목들(APOLLO_STATE 의 PlaceListBusinessesItem). @return list<array> */
    private function search(string $query): array
    {
        if (trim($query) === '') {
            return [];
        }
        $html = $this->get('https://pcmap.place.naver.com/place/list?query='.rawurlencode($query));
        if ($html === '' || ! preg_match('/window\.__APOLLO_STATE__\s*=\s*(\{.*?\});/s', $html, $m)) {
            return [];
        }
        $state = json_decode($m[1], true);
        if (! is_array($state)) {
            return [];
        }

        $items = [];
        foreach ($state as $k => $v) {
            if (is_array($v) && str_starts_with((string) $k, 'PlaceListBusinessesItem:')) {
                $items[] = $v;
            }
        }

        return $items;
    }

    /** 같은 시/군/구 + 상호 일치 항목 — 매칭 규칙은 NewBusinessPlaceMatcher 와 같게 유지한다. */
    private function pick(array $items, NewBusiness $biz): ?array
    {
        $target = $this->norm($biz->bplc_nm);
        $sgg = trim((string) $biz->sgg);
        foreach ($items as $it) {
            $title = $this->norm((string) ($it['name'] ?? ''));
            $addr = (string) ($it['fullAddress'] ?? '').' '.(string) ($it['commonAddress'] ?? '').' '.(string) ($it['roadAddress'] ?? '');
            $sameName = $title !== '' && $target !== ''
                && ($title === $target || str_contains($title, $target) || str_contains($target, $title));
            if ($sameName && ($sgg === '' || str_contains($addr, $sgg))) {
                return $it;
            }
        }

        return null;
    }

    /** 숨겨진 번호 — 응답이 base64 로 온다(없으면 404). */
    private function restPhone(string $id): ?string
    {
        $body = trim($this->get('https://pcmap-api.place.naver.com/rest/phone?id='.rawurlencode($id), [
            'accept: */*',
            'origin: https://pcmap.place.naver.com',
            'referer: https://pcmap.place.naver.com/restaurant/'.$id.'/home?from=map',
            'sec-fetch-dest: empty', 'sec-fetch-mode: cors', 'sec-fetch-site: same-site',
        ]));
        if ($body === '') {
            return null;
        }
        $json = base64_decode($body, true);
        if ($json === false) {
            return null;
        }
        $n = trim((string) (json_decode($json, true)['number'] ?? ''));

        return $n !== '' ? $n : null;
    }

    private function get(string $url, array $headers = []): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_ENCODING => '',   // gzip 자동 해제
            CURLOPT_HTTPHEADER => array_merge([
                'user-agent: '.config('rankfree.place.ua'),
                'accept-language: ko-KR,ko;q=0.9',
            ], $headers),
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            if ($code !== 404) {   // 404 는 "그 업체엔 없다" — 정상 흐름이라 로그를 남기지 않는다
                Log::warning('newbiz: place phone fetch failed', ['status' => $code, 'url' => explode('?', $url)[0]]);
            }

            return '';
        }

        return $body;
    }

    /** 상호 정규화 — 공백·특수문자 제거("풍납 주먹고기 풍납본점" ~ "풍납주먹고기"). */
    private function norm(string $s): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim($s), 'UTF-8'));
    }
}
