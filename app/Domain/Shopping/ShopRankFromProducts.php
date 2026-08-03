<?php

namespace App\Domain\Shopping;

/**
 * 확장(시장분석 수집기)이 가져온 상품 목록에서 순위를 판정한다(2026-08-03).
 *
 * openapi shop.json 이 종료돼(공지 32564) 서버가 직접 순위를 못 구한다. 확장은 검색 페이지와
 * **같은 오리진 + nCaptcha 토큰**으로 부르기 때문에 418·캡차 없이 목록을 가져올 수 있다 —
 * 매칭·순위 계산은 서버에 두어 규칙을 한 곳에서만 관리한다.
 *
 * 순위는 **광고를 뺀 오가닉 위치**다. 광고는 돈을 낸 자리라 순위추적의 의미가 아니다
 * (같은 상품이 광고로도 걸리면 ad=true 로 따로 알린다).
 */
class ShopRankFromProducts
{
    /**
     * @param  list<array<string,mixed>>  $products  확장 수집 상품(네이버 노출 순서 그대로)
     * @param  array{type:string,product_id:string,id_kind:string,mall_name:string}  $target
     * @return array{found:bool, rank:int, ad:bool, total:int, scanned:int, product_id:string, title:string, mall_name:string, price:int, link:string, image:string}
     */
    public function rank(array $products, array $target, int $total = 0): array
    {
        $type = ($target['type'] ?? '') === 'mall' ? 'mall' : 'product';
        $pid = preg_replace('/\D/', '', (string) ($target['product_id'] ?? ''));
        $kind = ($target['id_kind'] ?? 'channel') === 'nvmid' ? 'nvmid' : 'channel';
        $mall = $this->norm((string) ($target['mall_name'] ?? ''));

        $out = [
            'found' => false, 'rank' => 0, 'ad' => false, 'total' => $total, 'scanned' => count($products),
            'product_id' => (string) ($target['product_id'] ?? ''), 'title' => '',
            'mall_name' => (string) ($target['mall_name'] ?? ''), 'price' => 0, 'link' => '', 'image' => '',
        ];

        if ($type === 'product' ? $pid === '' : $mall === '') {
            return $out;
        }

        $organic = 0;
        foreach ($products as $p) {
            if (! is_array($p)) {
                continue;
            }
            $isAd = ! empty($p['isAd']);
            if (! $isAd) {
                $organic++;   // 오가닉만 센다
            }

            if (! $this->matches($p, $type, $pid, $kind, $mall)) {
                continue;
            }

            if ($isAd) {
                $out['ad'] = true;   // 광고 노출은 표시만 하고 계속 — 오가닉 자리를 찾는다

                continue;
            }

            $out['found'] = true;
            $out['rank'] = $organic;
            $out['title'] = $this->str($p['title'] ?? '');
            $out['mall_name'] = $this->str($p['mallName'] ?? $out['mall_name']);
            $out['price'] = (int) ($p['price'] ?? 0);
            $out['link'] = $this->str($p['link'] ?? '');
            $out['image'] = $this->str($p['image'] ?? $p['imageUrl'] ?? '');
            if ($out['product_id'] === '') {
                $out['product_id'] = (string) ($p['nvMid'] ?? $p['id'] ?? '');
            }
            break;
        }

        return $out;
    }

    /** 수집 상품이 대상과 같은가. */
    private function matches(array $p, string $type, string $pid, string $kind, string $mall): bool
    {
        if ($type === 'mall') {
            return $mall !== '' && str_contains($this->norm($this->str($p['mallName'] ?? '')), $mall);
        }

        if ($kind === 'nvmid') {
            // 가격비교 카탈로그 — nvMid 직접 비교
            foreach (['nvMid', 'id'] as $k) {
                if ($pid !== '' && (string) ($p[$k] ?? '') === $pid) {
                    return true;
                }
            }

            return false;
        }

        // 스마트스토어/브랜드 — 상품 URL 의 channelProductId
        $link = $this->str($p['link'] ?? '');
        if ($link !== '' && preg_match('#/products/(\d+)#', $link, $m) && $m[1] === $pid) {
            return true;
        }

        // 링크 형태가 바뀌어도 id 가 그대로 박혀 있으면 인정(느슨한 폴백)
        return $pid !== '' && $link !== '' && str_contains($link, $pid);
    }

    private function str(mixed $v): string
    {
        return trim((string) (is_scalar($v) ? $v : ''));
    }

    private function norm(string $s): string
    {
        return mb_strtolower(str_replace(' ', '', trim($s)), 'UTF-8');
    }
}
