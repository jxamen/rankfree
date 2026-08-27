<?php

namespace App\Domain\Order;

use Illuminate\Support\Facades\Http;

/**
 * 부스팅샵 플레이스 주문 API 클라이언트(2026-08-27) — https://boostings.shop/api/docs/place
 *
 * 특징(문서 기준):
 *  - Content-Type 은 form(application/x-www-form-urlencoded), 유입 키워드는 `search_keywords[]` 배열
 *  - **HTTP 상태코드는 항상 200** — 성공 여부는 body 의 `result`(success|fail)로 판정한다
 *  - 인증은 헤더가 아니라 폼 파라미터 `key`(시크릿) — 호출 시점에 붙이므로 발주 payload 에는 남기지 않는다
 */
class BoostingShopClient
{
    /**
     * 부스팅샵 플레이스 상품(문서 /api/docs/place) — **product_no 하나로 유입/저장과 등급이 모두 결정**된다.
     * 별도 서비스 구분값을 보내지 않으므로, 주문 상품이 유입인지 저장인지에 따라 이 표에서 고른다.
     *
     * @var array<string, array{label: string, grades: array<int, string>}>
     */
    public const PLACE_PRODUCTS = [
        'traffic' => ['label' => '플레이스 유입', 'grades' => [47 => '베이직', 48 => '프로', 49 => '엘리트', 50 => '프리미엄']],
        'save' => ['label' => '플레이스 저장', 'grades' => [52 => '베이직', 53 => '프로', 54 => '엘리트', 56 => '프리미엄2']],
    ];

    /** 프리미엄(50)은 smartcall_url 필수 — 화면 안내·검증에서 참조. */
    public const SMARTCALL_REQUIRED = [50];

    /** API 키가 설정돼 있는지 — 미설정이면 버튼을 눌러도 전송하지 않는다. */
    public function configured(): bool
    {
        return trim((string) config('rankfree.boosting_shop.api_key')) !== '';
    }

    /**
     * 주문 접수 — POST /api/order/place.
     *
     * @param  array<string, mixed>  $params  key 를 제외한 주문 파라미터(search_keywords 는 배열)
     * @return array{ok: bool, order_no: ?int, error: ?string, body: mixed, status: int}
     */
    public function place(array $params): array
    {
        return $this->post($params);
    }

    /**
     * 주문 조회 — POST /api/order/place (action=status).
     *
     * @return array{ok: bool, order_no: ?int, error: ?string, body: mixed, status: int}
     */
    public function status(int $orderNo): array
    {
        return $this->post(['action' => 'status', 'order_no' => $orderNo]);
    }

    /** @return array{ok: bool, order_no: ?int, error: ?string, body: mixed, status: int} */
    private function post(array $params): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'order_no' => null, 'error' => '부스팅샵 API 키가 설정되지 않았습니다 — .env BOOSTINGSHOP_API_KEY 를 확인하세요.', 'body' => null, 'status' => 0];
        }

        $url = config('rankfree.boosting_shop.base_url').'/api/order/place';
        $res = Http::timeout(30)->asForm()->post($url, $params + ['key' => (string) config('rankfree.boosting_shop.api_key')]);

        $body = $res->json();
        if (! is_array($body)) {
            // JSON 이 아니면(점검 페이지·오류 HTML 등) 응답 앞부분을 사유로 남긴다
            return ['ok' => false, 'order_no' => null, 'error' => 'HTTP '.$res->status().' — 응답을 해석하지 못했습니다: '.mb_substr(trim((string) $res->body()), 0, 300), 'body' => $res->body(), 'status' => $res->status()];
        }

        $ok = ($body['result'] ?? '') === 'success';

        return [
            'ok' => $ok,
            'order_no' => isset($body['order_no']) ? (int) $body['order_no'] : null,
            'error' => $ok ? null : (string) ($body['error'] ?? '알 수 없는 오류'),
            'body' => $body,
            'status' => $res->status(),
        ];
    }
}
