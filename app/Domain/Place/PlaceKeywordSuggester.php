<?php

namespace App\Domain\Place;

use Illuminate\Support\Facades\Cache;

/**
 * 유입 키워드 추천(2026-08-27) — 플레이스명·주소(지역)·업종을 섞어 후보를 만들고,
 * **그 키워드로 플레이스 순위에 실제로 잡히는 키워드만** 골라낸다.
 *
 * 부스팅샵 유입 미션은 참여자가 검색창에 키워드를 넣고 그 업체를 찾아 들어가는 방식이라,
 * 검색해도 안 나오는 키워드를 넣으면 미션이 성립하지 않는다.
 *
 * ⚠️ 판정 방식(2026-08-27 개정) — 처음에는 통합검색 HTML 에 placeId 가 있는지로 판정했으나
 * **거짓 양성이었다**: 네이버 SERP 의 블로그 썸네일 데이터에 `"gdid":"blog_…","sid":"<placeId>"`
 * 형태로 같은 숫자가 실려 있어, 플레이스가 안 보이는 키워드도 노출로 잡혔다(사용자 수동 확인으로 발견).
 * 지금은 [PlaceRankChecker](PlaceRankChecker.php) 로 **실제 순위를 조회**해 잡히는 키워드만 채택하고
 * 순위를 함께 돌려준다(플레이스 순위추적과 같은 엔진 · nCaptcha 토큰 사용).
 */
class PlaceKeywordSuggester
{
    /** 후보 검사 상한 — 키워드마다 순위조회를 돌리므로 무한정 늘리지 않는다. */
    private const MAX_CANDIDATES = 14;

    /** 추천 채택 기준 순위 — 이보다 뒤면 참여자가 찾지 못해 유입 미션에 쓸 수 없다. */
    private const RANK_LIMIT = 50;

    public function __construct(private PlaceRankChecker $checker) {}

    /**
     * 프로필 → 후보 생성 → 순위 확인.
     *
     * @param  array{place_id:string, name:string, category:string, address:string, ...}  $profile
     * @param  list<string>  $extra  기존 주문 키워드 등 함께 확인할 키워드
     * @return array{checked:int, blocked:bool, exposed:list<array{keyword:string,rank:int}>, missed:list<string>}
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
            return ['checked' => 0, 'blocked' => false, 'exposed' => [], 'missed' => []];
        }

        // 추천은 "이 키워드로 찾을 수 있나"만 보면 되므로 1페이지(50위)까지만 — 순위추적 기본값(6페이지)은 너무 느리다
        config(['rankfree.place.max_pages' => 1, 'rankfree.place.page_delay' => 0]);

        $exposed = $missed = [];
        $blocked = false;
        foreach ($candidates as $kw) {
            $r = $this->rank($kw, $pid, (string) ($profile['name'] ?? ''));
            if ($r['blocked']) {
                $blocked = true;      // 토큰 만료·IP 차단 — 남은 후보도 못 믿으므로 표시하고 중단
                break;
            }
            if ($r['rank'] > 0 && $r['rank'] <= self::RANK_LIMIT) {
                $exposed[] = ['keyword' => $kw, 'rank' => $r['rank']];
            } else {
                $missed[] = $kw;
            }
        }
        usort($exposed, fn ($a, $b) => $a['rank'] <=> $b['rank']);   // 잘 잡히는 키워드부터

        return [
            'checked' => count($exposed) + count($missed),
            'blocked' => $blocked,
            'exposed' => $exposed,
            'missed' => $missed,
        ];
    }

    /**
     * 키워드 1개의 플레이스 순위(6시간 캐시).
     *
     * @return array{rank:int, blocked:bool}
     */
    public function rank(string $keyword, string $placeId, string $placeName = ''): array
    {
        $kw = trim($keyword);
        $pid = preg_replace('/\D/', '', $placeId);
        if ($kw === '' || $pid === '') {
            return ['rank' => 0, 'blocked' => false];
        }

        $key = 'place:kwrank:'.$pid.':'.md5($kw);
        if (is_array($hit = Cache::get($key))) {
            return $hit;
        }

        $r = $this->checker->check($kw, $pid, $placeName !== '' ? $placeName : null);
        $out = ['rank' => (int) ($r['rank'] ?? 0), 'blocked' => (bool) ($r['blocked'] ?? false)];
        if (! $out['blocked']) {
            Cache::put($key, $out, now()->addHours(6));   // 차단된 결과는 캐시하지 않는다(토큰 복구 후 재조회)
        }

        return $out;
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
            $add($dongShort, '유명', $cat);
            $add($dongShort, $cat, '잘하는곳');
        }
        // 상호명 기반 — 상호 단독은 거의 확실히 잡힌다(브랜드 검색)
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
