<?php

namespace App\Domain\Shopping;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 네이버 쇼핑 검색 순위추적 — openapi.naver.com/v1/search/shop.json.
 * crm `naver_shopping_rank_api()` 이식. 키워드로 검색해 특정 상품(URL productId)
 * 또는 업체(mallName)의 노출 순위를 최대 max_pages×display(=1000)위까지 탐색.
 *
 * 다중 API 키(config rankfree.shopping.api_keys)를 429(한도 초과) 시 순차 로테이션한다.
 */
class NaverShoppingRankService
{
    /**
     * 상품 URL/업체명 입력 → 매칭 대상 파싱.
     * - smartstore/brand: /{...}/{...}/{...}/{...}/{productId}  → 경로 5번째
     * - search.shopping.naver.com: /.../{productId}             → 경로 4번째
     * - 그 외(URL 아님): 업체명(mallName) 매칭
     *
     * @return array{type:string, product_id:string, mall_name:string, url:string}
     */
    public function resolveTarget(string $input): array
    {
        $input = trim($input);
        $out = ['type' => 'mall', 'product_id' => '', 'mall_name' => '', 'url' => ''];

        if ($input === '') {
            return $out;
        }

        $isUrl = (bool) preg_match('#^https?://#i', $input) || str_contains($input, 'naver.com');
        if (! $isUrl) {
            // URL 이 아니면 업체명으로 취급 (컬럼 mall_name varchar(150) 초과 방지)
            $out['mall_name'] = mb_substr($input, 0, 150);

            return $out;
        }

        // 검색결과에서 복사한 URL 은 NaPm 등 추적 파라미터로 수백 자가 된다 —
        // 매칭에 쓰는 건 경로뿐이므로 쿼리스트링·프래그먼트는 버리고 저장한다.
        $path = explode('?', explode('#', $input)[0])[0];
        $out['url'] = $path;
        $seg = explode('/', $path);

        if (str_contains($input, 'smartstore') || str_contains($input, 'brand.naver')) {
            $out['product_id'] = isset($seg[5]) ? preg_replace('/\D/', '', $seg[5]) : '';
        } elseif (str_contains($input, 'search.shopping.naver.com')) {
            $out['product_id'] = isset($seg[4]) ? preg_replace('/\D/', '', $seg[4]) : '';
        } else {
            // 기타 쇼핑 URL — 마지막 숫자 세그먼트를 productId 로 추정
            foreach (array_reverse($seg) as $s) {
                if (ctype_digit($s) && strlen($s) >= 6) {
                    $out['product_id'] = $s;
                    break;
                }
            }
        }

        $out['type'] = $out['product_id'] !== '' ? 'product' : 'mall';
        if ($out['type'] === 'mall') {
            $out['mall_name'] = mb_substr($input, 0, 150); // productId 파싱 실패 → 입력을 업체명 후보로
        }

        return $out;
    }

    /**
     * 키워드에 대한 대상의 쇼핑 노출 순위 조회.
     *
     * @param  array  $target  resolveTarget() 결과
     * @return array{blocked:bool, found:bool, rank:int, total:int, product_id:string, title:string, mall_name:string, price:int, link:string, image:string, error?:string}
     */
    public function checkRank(string $keyword, array $target): array
    {
        $cfg = (array) config('rankfree.shopping');
        $keys = (array) ($cfg['api_keys'] ?? []);
        $result = [
            'blocked' => false, 'found' => false, 'rank' => 0, 'total' => 0,
            'product_id' => (string) ($target['product_id'] ?? ''),
            'title' => '', 'mall_name' => (string) ($target['mall_name'] ?? ''), 'price' => 0, 'link' => '', 'image' => '',
        ];

        $kw = str_replace(' ', '', trim($keyword));
        if ($kw === '' || empty($keys)) {
            $result['error'] = $kw === '' ? 'empty_keyword' : 'no_api_keys';

            return $result;
        }

        $type = $target['type'] ?? ($result['product_id'] !== '' ? 'product' : 'mall');
        $pid = $result['product_id'];
        $mall = $this->norm($result['mall_name']);
        if (($type === 'product' && $pid === '') || ($type === 'mall' && $mall === '')) {
            $result['error'] = 'invalid_target';

            return $result;
        }

        $display = (int) ($cfg['display'] ?? 100);
        $maxPages = (int) ($cfg['max_pages'] ?? 10);
        $delayMs = (int) ($cfg['page_delay_ms'] ?? 200);
        $timeout = (int) ($cfg['timeout'] ?? 15);

        // 키를 순차 시도: 429 만나면 다음 키로 처음부터 재스캔
        foreach ($keys as $key) {
            $rank = 0;
            $blocked = false;

            for ($p = 1; $p <= $maxPages; $p++) {
                $start = ($display * ($p - 1)) + 1;
                try {
                    $resp = Http::withHeaders([
                        'X-Naver-Client-Id' => (string) $key['id'],
                        'X-Naver-Client-Secret' => (string) $key['secret'],
                    ])->timeout($timeout)->get('https://openapi.naver.com/v1/search/shop.json', [
                        'query' => $kw, 'display' => $display, 'start' => $start, 'sort' => 'sim',
                    ]);
                } catch (Throwable $e) {
                    $result['error'] = 'http_exception';

                    return $result;
                }

                $code = $resp->status();
                if ($code === 429) {
                    $blocked = true;
                    break; // 다음 키로
                }
                if ($code !== 200) {
                    $result['error'] = 'http_'.$code;

                    return $result;
                }

                $json = (array) $resp->json();
                if ($p === 1) {
                    $result['total'] = (int) ($json['total'] ?? 0);
                }
                $items = (isset($json['items']) && is_array($json['items'])) ? $json['items'] : [];
                if (! count($items)) {
                    break; // 결과 소진
                }

                foreach ($items as $it) {
                    $rank++;
                    if ($this->matches($it, $type, $pid, $mall)) {
                        return $this->found($result, $it, $rank);
                    }
                }

                if (count($items) < $display) {
                    break; // 마지막 페이지
                }
                if ($p < $maxPages && $delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }

            if (! $blocked) {
                // 429 없이 전 범위 스캔 완료(미발견) — 앞 키가 429 였어도 이 키로 확인을
                // 마쳤으므로 차단 아님(잔존 blocked 로 '순위권 밖'이 -1 차단으로 오판되던 결함).
                $result['blocked'] = false;
                break;
            }
            $result['blocked'] = true; // 이 키는 막힘 — 다음 키 시도
        }

        $result['rank'] = 0;

        return $result;
    }

    /** shop.json item 이 대상과 일치하는가. */
    private function matches(array $it, string $type, string $pid, string $mall): bool
    {
        if ($type === 'product') {
            $tp = (string) ($it['productId'] ?? '');
            $link = (string) ($it['link'] ?? '');

            return $pid !== '' && ($tp === $pid || ($pid !== '' && str_contains($link, $pid)));
        }

        // 업체명(mallName) 일치 — 공백 제거 후 포함 비교
        return $mall !== '' && str_contains($this->norm((string) ($it['mallName'] ?? '')), $mall);
    }

    /** 매칭 item → 결과 채움(HTML 태그 제거). */
    private function found(array $result, array $it, int $rank): array
    {
        $strip = fn ($s) => trim(html_entity_decode(strip_tags((string) $s), ENT_QUOTES, 'UTF-8'));
        $result['found'] = true;
        $result['rank'] = $rank;
        $result['product_id'] = (string) ($it['productId'] ?? $result['product_id']);
        $result['title'] = $strip($it['title'] ?? '');
        $result['mall_name'] = (string) ($it['mallName'] ?? $result['mall_name']);
        $result['price'] = (int) ($it['lprice'] ?? 0);
        $result['link'] = (string) ($it['link'] ?? '');
        $result['image'] = (string) ($it['image'] ?? '');

        return $result;
    }

    private function norm(string $s): string
    {
        return mb_strtolower(str_replace(' ', '', trim($s)), 'UTF-8');
    }
}
