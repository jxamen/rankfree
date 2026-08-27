<?php

namespace App\Domain\Place;

use Illuminate\Support\Facades\Cache;

/**
 * 유입 키워드 추천(2026-08-27) — 플레이스명·주소(지역)·업종을 섞어 후보를 만들고,
 * **네이버 통합검색에 그 플레이스가 실제로 노출되는 키워드만** 골라낸다.
 *
 * 부스팅샵 유입 미션은 참여자가 검색창에 키워드를 넣고 그 업체를 찾아 들어가는 방식이라,
 * 검색해도 안 나오는 키워드를 넣으면 미션이 성립하지 않는다(주문에 적힌 키워드가 실제론
 * 노출되지 않는 경우가 있어 자동 확인이 필요하다).
 *
 * 판정: 모바일 통합검색 HTML 에 해당 placeId 가 등장하면 노출. 상호명은 동명업체가 있어 쓰지 않는다.
 */
class PlaceKeywordSuggester
{
    private const MO_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    /** 후보 검사 상한 — 통합검색 HTML 이 건당 1MB 안팎이라 무한정 늘리지 않는다. */
    private const MAX_CANDIDATES = 16;

    /**
     * 프로필 → 후보 생성 → 노출 확인.
     *
     * @param  array{place_id:string, name:string, category:string, address:string, ...}  $profile
     * @param  list<string>  $extra  기존 주문 키워드 등 함께 확인할 키워드
     * @return array{checked:int, exposed:list<string>, missed:list<string>}
     */
    public function suggest(array $profile, array $extra = []): array
    {
        $pid = (string) ($profile['place_id'] ?? '');
        $candidates = array_slice(
            $this->dedupe(array_merge($extra, $this->candidates($profile))),
            0,
            self::MAX_CANDIDATES,
        );
        if ($pid === '' || ! $candidates) {
            return ['checked' => 0, 'exposed' => [], 'missed' => []];
        }

        $exposed = $missed = [];
        foreach ($candidates as $kw) {
            if ($this->isExposed($kw, $pid)) {
                $exposed[] = $kw;
            } else {
                $missed[] = $kw;
            }
        }

        return ['checked' => count($candidates), 'exposed' => $exposed, 'missed' => $missed];
    }

    /**
     * 상호명 · 지역(시/구/동) · 업종을 섞은 키워드 후보.
     *
     * @param  array{name:string, category:string, address:string, ...}  $profile
     * @return list<string>
     */
    public function candidates(array $profile): array
    {
        $name = trim((string) ($profile['name'] ?? ''));
        $cat = trim((string) ($profile['category'] ?? ''));
        [$si, $gu, $dong] = $this->regionTokens((string) ($profile['address'] ?? ''));
        $dongShort = $this->trimSuffix($dong, ['동', '읍', '면', '리']);   // 초량동 → 초량 (검색은 보통 이 형태)

        $out = [];
        $add = function (string ...$parts) use (&$out) {
            $kw = trim(implode('', array_map('trim', array_filter($parts))));
            if ($kw !== '' && mb_strlen($kw) <= 25) {
                $out[] = $kw;
            }
        };

        // 업종 기반 — 지역을 좁은 곳부터 넓은 곳까지
        if ($cat !== '') {
            $add($dongShort, $cat);
            $add($dong, $cat);
            $add($gu, $cat);
            $add($si, $gu, $cat);
            $add($si, $cat);
            $add($dongShort, $cat, '추천');
            $add($gu, $cat, '추천');
            $add($si, $cat, '추천');
            $add($dongShort, '유명', $cat);
        }
        // 상호명 기반 — 상호 단독은 거의 확실히 노출된다(브랜드 검색)
        if ($name !== '') {
            $add($name);
            $add($dongShort, $name);
            $add($si, $name);
            if ($cat !== '') {
                $add($name, $cat);
            }
        }

        return $this->dedupe($out);
    }

    /** 키워드로 모바일 통합검색을 조회해 placeId 노출 여부 판정(6시간 캐시). */
    public function isExposed(string $keyword, string $placeId): bool
    {
        $kw = trim($keyword);
        $pid = preg_replace('/\D/', '', $placeId);
        if ($kw === '' || $pid === '') {
            return false;
        }

        return (bool) Cache::remember('place:serp:'.$pid.':'.md5($kw), now()->addHours(6), function () use ($kw, $pid) {
            usleep(300000);   // 연속 조회 간격 — 네이버 차단 회피(캐시가 있으면 여기까지 오지 않는다)
            $ch = curl_init('https://m.search.naver.com/search.naver?query='.rawurlencode($kw));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_ENCODING => '',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT => (int) config('rankfree.place.timeout', 20),
                CURLOPT_HTTPHEADER => [
                    'accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'accept-language: ko-KR,ko;q=0.9',
                    'user-agent: '.self::MO_UA,
                ],
            ]);
            $html = (string) curl_exec($ch);
            curl_close($ch);

            return $html !== '' && str_contains($html, $pid);
        });
    }

    /**
     * 지번 주소 → [시/도, 시군구, 동] (예: "부산 동구 초량동 42-13" → ["부산", "동구", "초량동"]).
     *
     * @return array{0:string,1:string,2:string}
     */
    public function regionTokens(string $address): array
    {
        $parts = preg_split('/\s+/', trim($address)) ?: [];
        $si = $parts[0] ?? '';
        $gu = $parts[1] ?? '';
        $dong = $parts[2] ?? '';
        // "경기 성남시 분당구 정자동" 처럼 4토막이면 시군구가 둘로 쪼개져 있다 — 뒤쪽(구)을 쓴다
        if (isset($parts[3]) && preg_match('/(구|군)$/u', $dong)) {
            $gu = $dong;
            $dong = $parts[3];
        }
        // 번지만 남은 칸은 지역명이 아니다
        if ($dong !== '' && preg_match('/^\d/', $dong)) {
            $dong = '';
        }

        return [$si, $gu, $dong];
    }

    /** @param list<string> $suffixes */
    private function trimSuffix(string $token, array $suffixes): string
    {
        foreach ($suffixes as $s) {
            if ($token !== $s && mb_strlen($token) > 1 && str_ends_with($token, $s)) {
                return mb_substr($token, 0, mb_strlen($token) - mb_strlen($s));
            }
        }

        return $token;
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private function dedupe(array $items): array
    {
        return array_values(array_unique(array_filter(array_map('trim', $items))));
    }
}
