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
     * core 키워드 모바일 검색 가격비교 결과에서 조합 재료 신호를 뽑는다(필터 붙여넣기 자동 대체).
     * 광고 제외 오가닉 상위의 판매몰명(=브랜드 후보)·상품명(=속성/수식어 후보)을 반환.
     *
     * @return array{brands:list<string>, product_names:list<string>}
     */
    public function keywordSignals(string $keyword, int $topN = 20, int $timeout = 10): array
    {
        $out = ['brands' => [], 'product_names' => []];
        $kw = trim($keyword);
        if ($kw === '') {
            return $out;
        }
        try {
            $r = Http::withHeaders([
                'user-agent' => self::MO_UA,
                'accept' => 'text/html,application/xhtml+xml',
                'referer' => 'https://m.search.naver.com/',
            ])->timeout($timeout)->get('https://m.search.naver.com/search.naver', ['where' => 'm', 'query' => $kw]);
        } catch (Throwable) {
            return $out;
        }
        if (! $r->ok()) {
            return $out;
        }

        $organic = 0;
        foreach (($this->parseSlots($r->body()) ?? []) as $s) {
            if (($s['sourceType'] ?? '') === 'AD') {
                continue;   // 광고 제외
            }
            if (++$organic > $topN) {
                break;
            }
            if (($s['mallName'] ?? '') !== '') {
                $out['brands'][] = $s['mallName'];
            }
            if (($s['productName'] ?? '') !== '') {
                $out['product_names'][] = $s['productName'];
            }
        }

        return $out;
    }

    /**
     * HTML 에서 가격비교 슬롯을 파싱해 대상의 순위·광고 노출을 계산(테스트용으로 분리).
     *
     * 순위 = **광고(sourceType AD) 를 제외한 문서상 노출 위치**(1,2,3…). 동일 상품이 광고로도 있으면 ad=true.
     * 매칭: 스마트스토어 상품 = channelProductId 일치 / 가격비교 카탈로그 = nvMid 일치 / 그 외 = 업체명.
     *
     * @return array{found:bool, rank:int, ad:bool, total:int}
     */
    public function rankFromHtml(string $html, array $target): array
    {
        $out = ['found' => false, 'rank' => 0, 'ad' => false, 'total' => 0];
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
            if (($s['sourceType'] ?? '') === 'AD') {
                if ($match) {
                    $out['ad'] = true;   // 동일 상품이 광고로도 노출 중
                }

                continue;                // 광고는 순위 카운트에서 제외
            }
            $organic++;                  // 광고 제외 문서상 위치
            if ($match && ! $out['found']) {
                $out['found'] = true;
                $out['rank'] = $organic;
            }
        }
        $out['total'] = $organic;

        return $out;
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
                        'productName' => trim(strip_tags((string) ($d['productName'] ?? ''))),
                    ];
                }
            }

            return $slots;
        }

        return null;
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
