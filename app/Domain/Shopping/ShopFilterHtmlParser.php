<?php

namespace App\Domain\Shopping;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Throwable;

/**
 * 네이버 쇼핑 검색결과 페이지 조각(붙여넣기 HTML)에서 필터 토큰을 추출한다.
 * 서버는 search.shopping.naver.com 을 직접 못 긁으므로(418), 사용자가 붙여넣은 HTML 을 파싱한다.
 *
 * 추출 대상(data-shp-contents-type 기준):
 *  - "브랜드"            → data-shp-contents-id (예: 종근당, 고려은단)
 *  - "키워드추천"        → data-shp-contents-dtl 의 filter_value_id (예: 비타민c3000 — contents-id 는 "3000" 처럼 조각이라 dtl 사용)
 *  - "…(속성)"          → data-shp-contents-id (예: 1개월분, 항산화, 500mg)
 *
 * @return array{brands:list<string>, keyword_recs:list<string>, attributes:list<string>}
 */
class ShopFilterHtmlParser
{
    /** @return array{brands:list<string>, keyword_recs:list<string>, attributes:list<string>} */
    public function parse(?string $html): array
    {
        $out = ['brands' => [], 'keyword_recs' => [], 'attributes' => []];
        $html = trim((string) $html);
        if ($html === '') {
            return $out;
        }

        try {
            $doc = new DOMDocument();
            $prev = libxml_use_internal_errors(true);
            // UTF-8 한글 보존을 위한 인코딩 힌트
            $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);

            $xp = new DOMXPath($doc);
            $nodes = $xp->query('//*[@data-shp-contents-type]');
            if ($nodes === false) {
                return $out;
            }

            foreach ($nodes as $el) {
                if (! $el instanceof DOMElement) {
                    continue;
                }
                $type = trim($el->getAttribute('data-shp-contents-type'));
                $id = trim($el->getAttribute('data-shp-contents-id'));

                if ($type === '브랜드') {
                    $this->push($out['brands'], $id);
                } elseif ($type === '키워드추천') {
                    $this->push($out['keyword_recs'], $this->filterValueId($el) ?: $id);
                } elseif (str_ends_with($type, '(속성)')) {
                    $this->push($out['attributes'], $id);
                }
            }
        } catch (Throwable) {
            return $out;
        }

        return $out;
    }

    /** data-shp-contents-dtl JSON 에서 filter_value_id 값을 꺼낸다(실제 검색어). */
    private function filterValueId(DOMElement $el): string
    {
        $dtl = $el->getAttribute('data-shp-contents-dtl');
        if ($dtl === '') {
            return '';
        }
        $arr = json_decode($dtl, true);
        if (! is_array($arr)) {
            return '';
        }
        foreach ($arr as $row) {
            if (is_array($row) && ($row['key'] ?? '') === 'filter_value_id') {
                return trim((string) ($row['value'] ?? ''));
            }
        }

        return '';
    }

    /** @param list<string> $bucket */
    private function push(array &$bucket, string $v): void
    {
        $v = trim($v);
        if ($v !== '' && ! in_array($v, $bucket, true)) {
            $bucket[] = $v;
        }
    }
}
