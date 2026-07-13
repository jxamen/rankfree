<?php

namespace App\Domain\Keyword;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 네이버 통합검색 PC/모바일 섹션 배치 순서 수집 — 일반 HTTP(curl)로 SERP HTML 을 받아 서버에서 파싱.
 * Playwright(headless) 미사용 — 서버 부하·지연 없이 수백 ms. 결과는 키워드당 24시간 캐시.
 */
class NaverSerpService
{
    private const PC_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36';

    private const MO_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    /** m.place 등 콘텐츠 섹션명(헤더 매칭용, 긴 이름 우선). */
    private const KNOWN = [
        '네이버플러스 스토어', '함께 많이 찾는', '새로 오픈했어요', '네이버 클립', '파워컨텐츠', '인플루언서',
        '스마트블록', '오디오클립', '플레이스', '파워링크', '비즈사이트', '가격비교', '지식백과', '어학사전',
        '지식iN', '웹사이트', '스토어', '라운지', '메이트', '블로그', '카페', '인기글', '이미지', '동영상',
        '뉴스', '쇼핑', '지도', '방송사', 'VIEW', '통합웹',
    ];

    /**
     * PC/모바일 섹션 순서 + 섹션별 콘텐츠 개수 + "함께 많이 찾는" 연관 키워드.
     *
     * @return array{pc:list<array{name:string,count:int}>,mobile:list<array{name:string,count:int}>,related:list<array{keyword:string,badge:string}>}|null
     */
    public function sections(string $keyword): ?array
    {
        $kw = trim($keyword);
        if ($kw === '') {
            return null;
        }
        // v6: "함께 많이 찾는" 연관키워드(qra API) 추가 → 캐시 키 갱신(구 캐시 무효화)
        $key = 'kw:serp6:'.md5(mb_strtoupper(str_replace(' ', '', $kw), 'UTF-8'));
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached;
        }

        $result = $this->collect($kw);
        if ($result !== null) {
            // 연관키워드("함께 많이 찾는")까지 채워졌을 때만 24h 캐시.
            // 비었으면(일시적 수집 실패 가능) 30분만 캐시해 곧 재시도되게 한다.
            $ttl = ! empty($result['related']) ? now()->addHours(24) : now()->addMinutes(30);
            Cache::put($key, $result, $ttl);
        }

        return $result;
    }

    /** PC/모바일 SERP HTML 수집 → 파싱. */
    private function collect(string $kw): ?array
    {
        $pcHtml = $this->fetch('https://search.naver.com/search.naver?query='.urlencode($kw), self::PC_UA);
        $moHtml = $this->fetch('https://m.search.naver.com/search.naver?query='.urlencode($kw), self::MO_UA);

        $pc = $pcHtml ? $this->parse($pcHtml) : [];
        $mobile = $moHtml ? $this->parse($moHtml) : [];
        $related = $pcHtml ? $this->relatedKeywords($pcHtml) : [];
        if (! $pc && ! $mobile) {
            return null;
        }

        return ['pc' => $pc, 'mobile' => $mobile, 'related' => $related];
    }

    /**
     * "함께 많이 찾는" 연관 키워드 — SERP HTML 에 박힌 qra 모듈 API URL(enc_pageid·enlu_query 토큰 포함)을
     * 추출해 1회 호출 → result.contents[].query 파싱.
     *
     * @return list<array{keyword:string,badge:string}>
     */
    private function relatedKeywords(string $html): array
    {
        if (! preg_match('#https://s\.search\.naver\.com/p/qra/1/search\.naver\?[^"\\\\\s]*#', $html, $m)) {
            return [];
        }
        $url = html_entity_decode($m[0], ENT_QUOTES, 'UTF-8');
        $json = $this->fetch($url, self::PC_UA);
        if (! $json) {
            return [];
        }
        $data = json_decode($json, true);
        $items = $data['result']['contents'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($items as $it) {
            $kw = trim((string) ($it['query'] ?? ''));
            if ($kw === '' || isset($seen[$kw])) {
                continue;
            }
            $seen[$kw] = true;
            $out[] = ['keyword' => $kw, 'badge' => trim((string) ($it['badge']['text'] ?? ''))];
        }

        return $out;
    }

    /** SERP HTML 1회 GET. */
    private function fetch(string $url, string $ua): ?string
    {
        try {
            $res = Http::withHeaders([
                'User-Agent' => $ua,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'ko-KR,ko;q=0.9',
                'Referer' => 'https://www.naver.com/',
            ])->timeout((int) config('rankfree.place.timeout', 15))->get($url);

            return $res->ok() ? $res->body() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** SERP HTML → 섹션 배치 [{name,count},…]. #main_pack 직계 자식을 문서 순서대로 분류. */
    private function parse(string $html): array
    {
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);
        $main = $xp->query('//*[@id="main_pack"]')->item(0) ?? $xp->query('//*[@id="ct"]')->item(0);
        if (! $main) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($main->childNodes as $el) {
            if (! $el instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($el->tagName);
            if (in_array($tag, ['script', 'link', 'style', 'noscript', 'template'], true)) {
                continue;
            }
            $cls = $el->getAttribute('class');
            if (preg_match('/_scrollLog|_search_option|api_sc_page_wrap|ct_feed_wrap|api_disp|_ac_|related_srch|_lazy_loading_wrap|api_flow/i', $cls)) {
                continue;
            }
            $hasHeader = $this->header($xp, $el) !== null;
            $isSection = (bool) preg_match('/place-app-root|sc_new|api_subject_bx|\bsp_/', $cls) || $tag === 'section' || $hasHeader;
            if (! $isSection) {
                continue;
            }

            $raw = $this->headerText($xp, $el);
            $hasLink = $xp->query('.//a[@href]', $el)->length > 0;
            $name = ($hasLink || $raw !== '') ? $this->classify($raw, $xp, $el) : '';
            // 링크·헤더 없는 클라이언트 렌더 모듈(예: "함께 많이 찾는") → data-meta-ssuid 로 JSON 제목 조회
            if ($name === '') {
                $name = $this->moduleTitle($el, $html);
            }
            if ($name === '' || mb_strlen($name) < 2 || preg_match('/^(정렬|기간|도움말|검색결과|연관|자동완성|옵션|신고|더보기|접기|필터|이전|다음|관련|이 광고)/u', $name)) {
                continue;
            }
            if (isset($seen[$name])) {
                continue;   // 배치 순서용 — 동일 섹션 중복은 첫 등장만
            }
            $seen[$name] = true;
            $out[] = ['name' => $name, 'count' => $this->countItems($xp, $el)];
        }

        return $out;
    }

    /**
     * 클라이언트 렌더 모듈(fender)의 섹션명 — DOM 은 빈 플레이스홀더이고 제목은 페이지 JSON 에만 있음.
     * data-meta-ssuid(예: "qra") → JSON 의 templateId(예: "qraND") 매칭으로 "title"(예: "함께 많이 찾는") 추출.
     */
    private function moduleTitle(DOMElement $el, string $html): string
    {
        $ssuid = $el->getAttribute('data-meta-ssuid');
        if ($ssuid === '' && preg_match('#^([A-Za-z0-9]+)/#', $el->getAttribute('data-block-id'), $m)) {
            $ssuid = $m[1];
        }
        if (! preg_match('/^[A-Za-z0-9]{2,12}$/', $ssuid)) {
            return '';
        }
        if (preg_match('/"title":"([^"]{1,24})"\}(?:,"profileName":"[^"]*"\})?,"templateId":"'.preg_quote($ssuid, '/').'[A-Za-z0-9]*"/u', $html, $mm)) {
            return trim($mm[1]);
        }

        return '';
    }

    private function header(DOMXPath $xp, DOMElement $el): ?DOMElement
    {
        $node = $xp->query('.//*[contains(concat(" ",@class," ")," api_title ") or contains(concat(" ",@class," ")," mod_title ") or contains(@class,"headline") or self::h2 or self::h3]', $el)->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function headerText(DOMXPath $xp, DOMElement $el): string
    {
        $h = $this->header($xp, $el);
        if (! $h) {
            return '';
        }
        $raw = preg_replace('/\s+/u', ' ', trim($h->textContent));
        $raw = preg_split('/\n/', $raw)[0] ?? $raw;
        $raw = preg_replace('/검색결과 안내.*$/u', '', $raw);
        $raw = preg_replace('/\s*(광고|AD|더보기)\s*$/u', '', $raw);

        return trim($raw);
    }

    /** 콘텐츠 출처 → 헤더 → 링크 호스트 → 외부 웹문서 → 짧은 헤더. */
    private function classify(string $raw, DOMXPath $xp, DOMElement $el): string
    {
        $hosts = $this->hosts($xp, $el);
        $cnt = fn (string $re) => array_sum(array_map(fn ($h, $c) => preg_match($re, $h) ? $c : 0, array_keys($hosts), $hosts));

        // 1) 콘텐츠 출처(고신뢰) — 헤더 substring 오탐(예: "쇼핑용어사전"→쇼핑)보다 우선
        if ($cnt('/terms\.naver/') >= 1) {
            return '지식백과';
        }
        if ($cnt('/dict\.naver/') >= 1) {
            return '어학사전';
        }
        if ($cnt('/kin\.naver/') >= 2) {
            return '지식iN';
        }

        // 2) 헤더에 알려진 섹션명(가장 긴 매치)
        $best = '';
        foreach (self::KNOWN as $k) {
            if (mb_strpos($raw, $k) !== false && mb_strlen($k) > mb_strlen($best)) {
                $best = $k;
            }
        }
        if ($best !== '') {
            return $best;
        }

        // 3) 링크 호스트로 판별
        $has = fn (string $re) => $cnt($re) > 0;
        if ($has('/ader\.naver|adcr\.naver|gfa\.naver|saedu\.naver/')) {
            return '파워링크';
        }
        if ($has('/place\.naver|pcmap|map\.naver/')) {
            return '플레이스';
        }
        if ($has('/cafe\.naver/')) {
            return '카페';
        }
        if ($has('/blog\.naver|post\.naver|in\.naver/')) {
            return '블로그';
        }
        if ($has('/news\.naver|n\.news\.naver/')) {
            return '뉴스';
        }
        if ($has('/shopping\.naver|smartstore|brand\.naver/')) {
            return '쇼핑';
        }
        if ($has('/tv\.naver|video\.naver|clip\.naver/')) {
            return '동영상';
        }

        // 4) 외부(비네이버) 링크 = 웹문서
        foreach (array_keys($hosts) as $h) {
            if (! preg_match('/(^|\.)naver\.com$|naver\.me$/', $h)) {
                return '웹사이트';
            }
        }

        // 5) 짧은 헤더는 그대로
        if ($raw !== '' && mb_strlen($raw) <= 14) {
            return $raw;
        }

        return '';
    }

    /** 섹션 내 링크 호스트 → 개수 맵. */
    private function hosts(DOMXPath $xp, DOMElement $el): array
    {
        $hosts = [];
        foreach ($xp->query('.//a[@href]', $el) as $a) {
            $host = parse_url($a->getAttribute('href'), PHP_URL_HOST);
            if ($host) {
                $host = preg_replace('/^(www|m)\./', '', strtolower($host));
                $hosts[$host] = ($hosts[$host] ?? 0) + 1;
            }
        }

        return $hosts;
    }

    /**
     * 섹션 콘텐츠(항목) 개수 추정 — leaf li(리스트형) → 제목 링크 → sds/fds 카드형 순.
     * SSR 시점 기준이라 지연로딩 콘텐츠 피드(뉴스·인기글 등)는 실제보다 적게 잡힐 수 있음(근사치).
     */
    private function countItems(DOMXPath $xp, DOMElement $el): int
    {
        $n = $xp->query('.//li[.//a[@href] and not(.//li)]', $el)->length;
        if ($n === 0) {
            $n = $xp->query('.//*[contains(@class,"title_link") or contains(@class,"total_tit") or contains(@class,"api_txt_lines") or contains(@class,"fds-comps-right-image-text-title")]', $el)->length;
        }
        if ($n === 0) {
            // sds/fds 카드형: 항목 대표 제목 요소
            $n = $xp->query('.//*[contains(@class,"text-type-headline") or contains(@class,"info-title") or contains(@class,"_text_title") or contains(@class,"link_tit")]', $el)->length;
        }
        if ($n === 0) {
            $n = $xp->query('.//a[@href][contains(@class,"title") or contains(@class,"tit")]', $el)->length;
        }

        return $n;
    }
}
