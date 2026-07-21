<?php

namespace App\Domain\Shopping;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 네이버 모바일 검색(m.search.naver.com) '가격비교(newshopping)' 영역의 **오가닉 노출 순위**를 찾는다.
 *
 * 페이지에 박힌 `newshopping["shopping"]._INITIAL_STATE = {...}` (initProps.pagedSlot[].slots[].data) 를 파싱해
 *  - 광고(`sourceType === "AD"`) 는 제외하고 대상 상품의 오가닉 순위를 구하고,
 *  - 동일 상품이 광고 슬롯에도 있으면 ad=true 로 별도 표기한다.
 * shop.json(openapi, sort=sim) 과 달리 실제 모바일 검색 노출에 가깝고 API 키 쿼터를 쓰지 않는다.
 */
class NaverShopExposureService
{
    private const MO_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    /**
     * @param  array  $target  resolveTarget() 결과(type/product_id/mall_name)
     * @return array{found:bool, rank:int, ad:bool, total:int, blocked:bool, error?:string}
     */
    public function exposure(string $keyword, array $target, int $timeout = 10): array
    {
        $out = ['found' => false, 'rank' => 0, 'ad' => false, 'total' => 0, 'blocked' => false];
        $kw = trim($keyword);
        if ($kw === '') {
            $out['error'] = 'empty_keyword';

            return $out;
        }

        try {
            $r = Http::withHeaders([
                'user-agent' => self::MO_UA,
                'accept' => 'text/html,application/xhtml+xml',
                'referer' => 'https://m.search.naver.com/',
            ])->timeout($timeout)->get('https://m.search.naver.com/search.naver', ['where' => 'm', 'query' => $kw]);
        } catch (Throwable) {
            $out['blocked'] = true;
            $out['error'] = 'http_exception';

            return $out;
        }

        if (! $r->ok()) {
            $out['blocked'] = true;
            $out['error'] = 'http_'.$r->status();

            return $out;
        }

        return $this->rankFromHtml($r->body(), $target) + $out;
    }

    /**
     * core 키워드 모바일 검색 가격비교 결과에서 조합 재료 신호를 뽑는다.
     *  - product_names: 광고 제외 오가닉 상위 상품명(=속성 후보)
     *  - me: 대상 상품이 결과에 있으면 그 제목·업체명·가격(제목 단어 조합의 핵심 재료)
     *
     * @return array{product_names:list<string>, competitor_malls:list<string>, me:?array{title:string, mall:string, price:int}}
     */
    public function keywordSignals(string $keyword, array $target = [], int $topN = 20, int $timeout = 10): array
    {
        $kw = trim($keyword);
        if ($kw === '') {
            return ['product_names' => [], 'competitor_malls' => [], 'me' => null];
        }
        try {
            $r = Http::withHeaders([
                'user-agent' => self::MO_UA,
                'accept' => 'text/html,application/xhtml+xml',
                'referer' => 'https://m.search.naver.com/',
            ])->timeout($timeout)->get('https://m.search.naver.com/search.naver', ['where' => 'm', 'query' => $kw]);
        } catch (Throwable) {
            return ['product_names' => [], 'competitor_malls' => [], 'me' => null];
        }
        if (! $r->ok()) {
            return ['product_names' => [], 'competitor_malls' => [], 'me' => null];
        }

        return $this->signalsFromHtml($r->body(), $target, $topN);
    }

    /**
     * 확장(브라우저)이 가져온 m.search HTML 에서 keywordSignals 와 같은 신호를 뽑는다 — 서버 fetch 불필요.
     *
     * @return array{product_names:list<string>, competitor_malls:list<string>, me:?array{title:string, mall:string, price:int}}
     */
    public function signalsFromHtml(string $html, array $target = [], int $topN = 20): array
    {
        $out = ['product_names' => [], 'competitor_malls' => [], 'me' => null];

        $idKind = (string) ($target['id_kind'] ?? 'channel');
        $pid = (string) ($target['product_id'] ?? '');
        $mall = $this->norm((string) ($target['mall_name'] ?? ''));

        $organic = 0;
        foreach (($this->parseSlots($html) ?? []) as $s) {
            // 대상 상품이 결과(광고 포함)에 있으면 제목·업체·가격 확보
            if ($out['me'] === null && $this->matches($s, $idKind, $pid, $mall)) {
                $out['me'] = [
                    'title' => (string) ($s['productName'] ?? ''),
                    'mall' => (string) ($s['mallName'] ?? ''),
                    'price' => (int) ($s['price'] ?? 0),
                ];
            }
            if ($this->isAdSlot($s)) {
                continue;   // 속성·경쟁브랜드 후보는 광고성 슬롯(AD·슈퍼적립) 제외
            }
            if (++$organic <= $topN) {
                if (($s['productName'] ?? '') !== '') {
                    $out['product_names'][] = $s['productName'];
                }
                if (($s['mallName'] ?? '') !== '') {
                    $out['competitor_malls'][] = $s['mallName'];
                }
            }
        }

        // 브랜드 필터의 브랜드명(10개+) — 슬롯 mallName 이 희소해도 경쟁 브랜드를 채운다
        $out['competitor_malls'] = array_values(array_unique(array_merge(
            $out['competitor_malls'], $this->brandNamesFromState($this->decodeState($html))
        )));

        return $out;
    }

    /**
     * HTML 에서 가격비교 슬롯을 파싱해 대상의 순위·광고 노출을 계산(테스트용으로 분리).
     *
     * 순위 = **광고(sourceType AD) 를 제외한 문서상 노출 위치**(1,2,3…). 동일 상품이 광고로도 있으면 ad=true.
     * 매칭: 스마트스토어 상품 = channelProductId 일치 / 가격비교 카탈로그 = nvMid 일치 / 그 외 = 업체명.
     * 매칭 슬롯(광고 포함)을 만나면 me(제목·업체명·가격)도 채워 준다 — 분석 헤더 백필용.
     *
     * @return array{found:bool, rank:int, ad:bool, total:int, me:?array{title:string, mall:string, price:int}}
     */
    public function rankFromHtml(string $html, array $target): array
    {
        $out = ['found' => false, 'rank' => 0, 'ad' => false, 'total' => 0, 'me' => null];
        $slots = $this->parseSlots($html);
        if (! $slots) {
            return $out;   // 가격비교 미노출 키워드 — 노출 0(오류 아님)
        }

        $idKind = (string) ($target['id_kind'] ?? 'channel');   // 'channel' | 'nvmid'
        $pid = (string) ($target['product_id'] ?? '');
        $mall = $this->norm((string) ($target['mall_name'] ?? ''));

        $organic = 0;
        foreach ($slots as $s) {
            $match = $this->matches($s, $idKind, $pid, $mall);
            if ($match && $out['me'] === null) {
                $out['me'] = [
                    'title' => (string) ($s['productName'] ?? ''),
                    'mall' => (string) ($s['mallName'] ?? ''),
                    'price' => (int) ($s['price'] ?? 0),
                ];
            }
            if ($this->isAdSlot($s)) {
                if ($match) {
                    $out['ad'] = true;   // 동일 상품이 광고(쇼핑검색광고·슈퍼적립)로 노출 중
                }

                continue;                // 광고성 슬롯은 오가닉 순위에서 제외
            }
            $organic++;
            if ($match && ! $out['found']) {
                $out['found'] = true;
                // 네이버 자체 순위 필드 우선 — 슬롯 문서 순서는 lazy-load 페이지 단위로 섞인다(실측).
                // rank 시퀀스는 슈퍼적립(1) 포함 페이지 표기 순번이라 실제 화면 위치와 일치한다.
                $out['rank'] = (int) ($s['rank'] ?? 0) >= 1 ? (int) $s['rank'] : $organic;
            }
        }
        $out['total'] = $organic;

        return $out;
    }

    /**
     * 광고성 슬롯 판별 — AD(쇼핑검색광고) + SUPER_POINT(슈퍼적립 유료 프로모션).
     * 실측('비타민c 유유제약'): 슬롯 rank 시퀀스가 AD / SUPER_POINT+SAS 로 나뉘고,
     * SUPER_POINT 는 화면에 광고성 표기가 붙는 유료 슬롯이라 오가닉 순위로 세면 안 된다.
     */
    private function isAdSlot(array $s): bool
    {
        return in_array((string) ($s['sourceType'] ?? ''), ['AD', 'SUPER_POINT'], true);
    }

    private function matches(array $s, string $idKind, string $pid, string $mall): bool
    {
        if ($pid !== '') {
            if ($idKind === 'nvmid') {
                return (string) ($s['nvMid'] ?? '') === $pid;   // 가격비교 카탈로그
            }

            return (string) ($s['channelProductId'] ?? '') === $pid;   // 스마트스토어/브랜드 상품
        }

        return $mall !== '' && str_contains($this->norm((string) ($s['mallName'] ?? '')), $mall);
    }

    /**
     * newshopping 가격비교 _INITIAL_STATE 블록(initProps.pagedSlot 보유)에서 슬롯 목록을 순서대로 추출.
     *
     * @return list<array{sourceType:string, channelProductId:string, nvMid:string, productUrl:string, mallName:string, productName:string}>|null
     */
    private function parseSlots(string $html): ?array
    {
        $data = $this->decodeState($html);
        if ($data === null) {
            return null;
        }

        $slots = [];
        foreach ($data['initProps']['pagedSlot'] as $page) {
            foreach (($page['slots'] ?? []) as $slot) {
                $d = $slot['data'] ?? null;
                if (! is_array($d)) {
                    continue;
                }
                $slots[] = [
                    'sourceType' => (string) ($d['sourceType'] ?? ''),
                    'rank' => (int) ($d['rank'] ?? 0),
                    'channelProductId' => (string) ($d['channelProductId'] ?? ''),
                    'nvMid' => (string) ($d['nvMid'] ?? ''),
                    'productUrl' => (string) ($d['productUrl']['mobileUrl'] ?? $d['productUrl']['pcUrl'] ?? ''),
                    'mallName' => (string) ($d['mallName'] ?? ''),
                    'price' => (int) ($d['discountedSalePrice'] ?? $d['salePrice'] ?? 0),
                    'productName' => trim(strip_tags((string) ($d['productName'] ?? ''))),
                ];
            }
        }

        return $slots;
    }

    /** 가격비교 _INITIAL_STATE(JS 리터럴)를 JSON 으로 정리해 디코드. pagedSlot 이 있는 블록만. */
    private function decodeState(string $html): ?array
    {
        $offset = 0;
        while (($pos = strpos($html, '_INITIAL_STATE', $offset)) !== false) {
            $offset = $pos + 14;
            $brace = strpos($html, '{', $pos);
            if ($brace === false) {
                continue;
            }
            $obj = $this->extractBalanced($html, $brace);
            if ($obj === null || ! str_contains($obj, 'pagedSlot')) {
                continue;
            }
            // JS 리터럴 → JSON: undefined·new Date(...) 제거
            $json = preg_replace('/new Date\([^)]*\)/', 'null', $obj);
            $json = preg_replace('/\bundefined\b/', 'null', $json);
            $data = json_decode($json, true);
            if (! is_array($data) || empty($data['initProps']['pagedSlot'])) {
                continue;
            }

            return $data;
        }

        return null;
    }

    /**
     * 가격비교 브랜드 필터의 브랜드명 목록 — mallName 이 희소해도 경쟁 브랜드를 10개+ 채워 준다.
     * state 어디에 있든 filterSet(id=brand).values[].name 을 재귀로 찾는다.
     *
     * @return list<string>
     */
    private function brandNamesFromState(?array $data): array
    {
        if ($data === null) {
            return [];
        }
        $out = [];
        $walk = function ($node) use (&$walk, &$out): void {
            if (! is_array($node)) {
                return;
            }
            foreach (($node['filterSet'] ?? []) as $set) {
                if (is_array($set) && (($set['id'] ?? '') === 'brand' || ($set['filterName'] ?? '') === 'brand')) {
                    foreach (($set['values'] ?? []) as $v) {
                        $n = trim((string) ($v['name'] ?? ''));
                        if ($n !== '') {
                            $out[] = $n;
                        }
                    }
                }
            }
            foreach ($node as $v) {
                $walk($v);
            }
        };
        $walk($data);

        return array_values(array_unique($out));
    }

    /** 중괄호 균형 맞춰 객체 리터럴 추출(문자열 이스케이프 고려). */
    private function extractBalanced(string $s, int $start): ?string
    {
        $depth = 0;
        $n = strlen($s);
        $inStr = false;
        $esc = false;
        for ($i = $start; $i < $n; $i++) {
            $c = $s[$i];
            if ($inStr) {
                if ($esc) {
                    $esc = false;
                } elseif ($c === '\\') {
                    $esc = true;
                } elseif ($c === '"') {
                    $inStr = false;
                }

                continue;
            }
            if ($c === '"') {
                $inStr = true;
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($s, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    private function norm(string $s): string
    {
        return mb_strtolower(str_replace(' ', '', trim($s)), 'UTF-8');
    }
}
