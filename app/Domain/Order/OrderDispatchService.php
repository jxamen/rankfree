<?php

namespace App\Domain\Order;

use App\Models\MarketingOrder;
use App\Models\OrderDispatch;
use App\Models\ProductVendor;
use Illuminate\Support\Facades\Http;

/**
 * 주문 승인 → 외부 업체 자동 발주.
 * 상품에 설정된 업체 배분(비율/고정 수량)대로 수량을 나누고,
 * 업체별 field_map 매핑으로 페이로드를 만들어 채널(API | 구글시트)로 전송한다.
 */
class OrderDispatchService
{
    /** 승인 시 호출 — 배분 계산 → 전송 기록 생성 → 즉시 전송. 활성(미취소) 발주가 있으면 건너뜀(취소 후 재발주 허용). */
    public function dispatch(MarketingOrder $order): array
    {
        if ($order->dispatches()->where('status', '!=', 'canceled')->exists()) {
            return ['ok' => false, 'message' => '이미 발주된 주문입니다. 실패 건은 개별 재전송하거나, 발주를 취소한 뒤 다시 발주하세요.'];
        }

        $rows = $order->product->vendorAllocations()->with('vendor')->where('is_active', true)->orderBy('sort_order')->get()
            ->filter(fn ($pv) => $pv->vendor && $pv->vendor->is_active);
        if ($rows->isEmpty()) {
            return ['ok' => false, 'message' => '이 상품에 활성화된 업체 배분 설정이 없습니다. 상품 편집에서 먼저 설정하세요.'];
        }

        $allocs = $this->allocate($order->quantity, $rows->values());

        $dispatches = [];
        foreach ($allocs as [$pv, $qty]) {
            if ($qty <= 0) {
                continue;
            }
            $payload = $this->buildPayload($order, $pv, $qty);
            $d = OrderDispatch::create([
                'order_id' => $order->id,
                'vendor_id' => $pv->vendor->id,
                'vendor_name' => $pv->vendor->name,
                'channel' => $pv->vendor->channel,
                'quantity' => $qty,
                'payload' => $payload,
                'status' => 'pending',
            ]);
            $this->send($d);
            $dispatches[] = $d->refresh();
        }

        $sent = collect($dispatches)->where('status', 'sent')->count();
        $failed = collect($dispatches)->where('status', 'failed')->count();

        return [
            'ok' => true,
            'dispatches' => $dispatches,
            'message' => '업체 '.count($dispatches).'곳 발주 — 성공 '.$sent.' · 실패 '.$failed,
        ];
    }

    /** 실패/대기 건 재전송. */
    public function retry(OrderDispatch $d): OrderDispatch
    {
        $this->send($d);

        return $d->refresh();
    }

    /**
     * 수량 배분 — 설정 순서대로 고정 수량은 그대로, 비율은 주문수량×%(내림).
     * 남는 수량은 마지막 비율 행에 더해 전량 배분되게 한다(비율 행이 없으면 미배분 허용).
     *
     * @return array<int, array{0: ProductVendor, 1: int}>
     */
    public function allocate(int $total, $rows): array
    {
        $out = [];
        $remaining = $total;
        $lastRatioIdx = null;
        foreach ($rows as $i => $pv) {
            $qty = $pv->alloc_type === 'fixed'
                ? min($pv->alloc_value, max(0, $remaining))
                : (int) floor($total * $pv->alloc_value / 100);
            $qty = min($qty, max(0, $remaining));
            $remaining -= $qty;
            if ($pv->alloc_type === 'ratio') {
                $lastRatioIdx = $i;
            }
            $out[] = [$pv, $qty];
        }
        if ($remaining > 0 && $lastRatioIdx !== null) {
            $out[$lastRatioIdx][1] += $remaining;   // 내림 잔여분은 마지막 비율 업체로
        }

        return $out;
    }

    /** field_map [{key, src, value}] → 전송 페이로드. src: order:*, product:title, field:<field_key>, alloc:quantity, static. */
    public function buildPayload(MarketingOrder $order, ProductVendor $pv, int $qty): array
    {
        $map = collect((array) $pv->field_map)->filter(fn ($m) => trim((string) ($m['key'] ?? '')) !== '');
        if ($map->isEmpty()) {
            // 매핑 미설정 — 기본 페이로드(전체 정보)
            return [
                'order_no' => $order->order_no,
                'product' => $order->product->title,
                'quantity' => $qty,
                'days' => $order->days,
                'fields' => $order->field_values,
            ];
        }

        $out = [];
        foreach ($map as $m) {
            $out[trim($m['key'])] = $this->resolve($order, $qty, (string) ($m['src'] ?? 'static'), (string) ($m['value'] ?? ''));
        }

        return $out;
    }

    private function resolve(MarketingOrder $order, int $qty, string $src, string $static)
    {
        if ($src === 'static') {
            return $static;
        }
        [$type, $key] = array_pad(explode(':', $src, 2), 2, '');

        return match ($type) {
            'skip' => '',                                           // 보내지 않음 — 구글시트 열 위치만 차지(빈 셀)
            'alloc' => $qty,                                        // 이 업체에 배분된 수량
            'order' => match ($key) {
                'order_no' => $order->order_no,
                'quantity' => $order->quantity,                     // 주문 전체 수량
                'days' => $order->days,
                'unit_price' => (float) $order->unit_price,
                'total_price' => (float) $order->total_price,
                'orderer_name' => $order->orderer_name,
                'orderer_contact' => $order->orderer_contact,
                'created_at' => $order->created_at?->format('Y-m-d H:i'),
                default => null,
            },
            'product' => $order->product->title,
            'field' => $order->field_values[$key] ?? null,          // 동적 주문 필드 값
            default => null,
        };
    }

    /** 채널별 전송 — 결과를 dispatch 레코드에 기록. */
    private function send(OrderDispatch $d): void
    {
        try {
            $ok = $d->channel === 'gsheet'
                ? $this->sendGsheet($d)
                : $this->sendApi($d);
        } catch (\Throwable $e) {
            $ok = false;
            $d->response = mb_substr('오류: '.$e->getMessage(), 0, 1900);
        }

        $d->status = $ok ? 'sent' : 'failed';
        $d->sent_at = now();
        $d->save();
    }

    private function sendApi(OrderDispatch $d): bool
    {
        $v = $d->vendor;
        if (! $v || trim((string) $v->api_url) === '') {
            $d->response = 'API URL이 설정되지 않았습니다.';

            return false;
        }
        $req = Http::timeout(20)->withHeaders($v->headers());
        $method = strtolower($v->api_method ?: 'POST');
        $payload = (array) $d->payload;

        $res = match ($method) {
            'get' => $req->get($v->api_url, $payload),
            'put' => $v->api_format === 'form' ? $req->asForm()->put($v->api_url, $payload) : $req->put($v->api_url, $payload),
            default => $v->api_format === 'form' ? $req->asForm()->post($v->api_url, $payload) : $req->post($v->api_url, $payload),
        };
        $d->response = mb_substr('HTTP '.$res->status().' — '.$res->body(), 0, 1900);

        return $res->successful();
    }

    /** 구글시트 append — 서비스 계정(.env GOOGLE_SERVICE_ACCOUNT_JSON=json 파일 경로) JWT 인증. 시트는 서비스 계정 이메일에 공유돼 있어야 한다. */
    private function sendGsheet(OrderDispatch $d): bool
    {
        $v = $d->vendor;
        if (! $v || trim((string) $v->gsheet_id) === '') {
            $d->response = '구글시트 ID가 설정되지 않았습니다.';

            return false;
        }
        $token = \App\Support\GoogleServiceAccount::token('https://www.googleapis.com/auth/spreadsheets');
        if (! $token) {
            $d->response = '구글 서비스 계정 인증 실패 — .env GOOGLE_SERVICE_ACCOUNT_JSON(키 파일 경로)을 확인하세요.';

            return false;
        }

        // 탭 우선순위(2026-07-22): 상품×업체 배분 탭(sheet_tab) → 업체 기본(gsheet_tab) → 첫 탭.
        // 같은 업체를 쓰는 상품마다 다른 탭으로 보낼 수 있다.
        $allocTab = (string) \App\Models\ProductVendor::where('product_id', $d->order?->product_id)
            ->where('vendor_id', $v->id)->value('sheet_tab');
        $tab = trim($allocTab) ?: trim((string) $v->gsheet_tab);
        if ($tab === '') {
            $meta = Http::timeout(15)->withToken($token)
                ->get("https://sheets.googleapis.com/v4/spreadsheets/{$v->gsheet_id}?fields=sheets.properties.title");
            $tab = trim((string) ($meta->json('sheets.0.properties.title') ?? '')) ?: '시트1';
        }
        $range = rawurlencode("'{$tab}'!A1");
        $row = array_map(fn ($x) => is_scalar($x) || $x === null ? $x : json_encode($x, JSON_UNESCAPED_UNICODE), array_values((array) $d->payload));
        $res = Http::timeout(20)->withToken($token)->post(
            "https://sheets.googleapis.com/v4/spreadsheets/{$v->gsheet_id}/values/{$range}:append?valueInputOption=USER_ENTERED",
            ['values' => [$row]],
        );
        $d->response = mb_substr('HTTP '.$res->status().' — '.$res->body(), 0, 1900);

        return $res->successful();
    }

}
