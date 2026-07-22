<?php

namespace App\Http\Controllers\Api;

use App\Domain\Order\OrderInputException;
use App\Domain\Order\OrderPlacer;
use App\Http\Controllers\Controller;
use App\Models\MarketingOrder;
use App\Models\MarketingProduct;
use Illuminate\Http\Request;

/**
 * 외부 주문 API v1 (auth.apikey:order) — /admin/products 의 활성 상품을 API 키로 주문.
 * 주문 검증·금액 계산은 웹 주문과 동일한 OrderPlacer 를 공유한다(로직 이원화 금지).
 * 파일 첨부 필드는 API 미지원 — 필수 파일 필드가 있는 상품은 orderable=false 로 안내.
 */
class OrderApiController extends Controller
{
    /** 주문 가능 상품 목록. */
    public function products(OrderPlacer $placer)
    {
        $products = MarketingProduct::with('fields')->where('is_active', true)->orderBy('title')->get();

        return response()->json([
            'products' => $products->map(fn ($p) => $this->productJson($p, $placer))->values(),
        ]);
    }

    /** 상품 상세 — 주문 필드 스펙 포함. */
    public function product(int $id, OrderPlacer $placer)
    {
        $p = MarketingProduct::with('fields')->where('is_active', true)->find($id);
        if (! $p) {
            return response()->json(['message' => '상품을 찾을 수 없거나 판매 중이 아닙니다.'], 404);
        }

        return response()->json(['product' => $this->productJson($p, $placer, detail: true)]);
    }

    /**
     * 주문 생성.
     * body: { product_id, quantity?, days?, fields?: {field_key: 값}, user_coupon_id? }
     *  - 고정 수량·기간 상품은 quantity/days 를 보내도 서버 고정값이 우선한다.
     *  - 상품에 daily_qty/start_date/end_date 필드가 있으면 fields 안에 넣는다(상세 조회의 fields 스펙 참조).
     */
    public function store(Request $request, OrderPlacer $placer)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'days' => ['nullable', 'integer', 'min:1'],
            'fields' => ['nullable', 'array'],
            'user_coupon_id' => ['nullable', 'integer'],
        ]);

        $product = MarketingProduct::with('fields')->where('is_active', true)->find($data['product_id']);
        if (! $product) {
            return response()->json(['message' => '상품을 찾을 수 없거나 판매 중이 아닙니다.', 'field' => 'product_id'], 404);
        }
        if ($fileFields = $placer->requiredFileFields($product)) {
            return response()->json([
                'message' => '파일 첨부가 필수인 상품은 API 주문을 지원하지 않습니다: '.implode(', ', $fileFields),
                'field' => 'product_id',
            ], 422);
        }

        // fields 는 스칼라만 허용(파일 경로 주입 방지) — 배열 값은 MULTI_SELECT 용으로 문자열 배열만
        $fields = [];
        foreach ((array) ($data['fields'] ?? []) as $k => $v) {
            if (is_array($v)) {
                $fields[$k] = array_values(array_filter(array_map(fn ($x) => is_scalar($x) ? (string) $x : null, $v), fn ($x) => $x !== null));
            } elseif (is_scalar($v) || $v === null) {
                $fields[$k] = $v === null ? null : (string) $v;
            }
        }

        try {
            $order = $placer->place($request->user(), $product, $fields, [
                'quantity' => $data['quantity'] ?? null,
                'days' => $data['days'] ?? null,
                'user_coupon_id' => $data['user_coupon_id'] ?? null,
            ]);
        } catch (OrderInputException $e) {
            return response()->json(['message' => $e->getMessage(), 'field' => $e->field], 422);
        }

        return response()->json(['order' => $this->orderJson($order->fresh('product'))], 201);
    }

    /** 내 주문 목록 — status 필터 · 페이지네이션(page, per_page ≤ 100). */
    public function index(Request $request)
    {
        $q = MarketingOrder::with('product')->where('user_id', $request->user()->id)->latest();
        if (($st = $request->query('status')) && isset(MarketingOrder::STATUSES[$st])) {
            $q->where('status', $st);
        }
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));
        $page = $q->paginate($perPage);

        return response()->json([
            'orders' => collect($page->items())->map(fn ($o) => $this->orderJson($o))->values(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $perPage, 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** 주문 단건 조회(주문번호) — 본인 주문만. */
    public function show(Request $request, string $orderNo)
    {
        $order = MarketingOrder::with('product')
            ->where('order_no', $orderNo)->where('user_id', $request->user()->id)->first();
        if (! $order) {
            return response()->json(['message' => '주문을 찾을 수 없습니다.'], 404);
        }

        return response()->json(['order' => $this->orderJson($order)]);
    }

    // ── 직렬화 ────────────────────────────────────────────────────────
    private function productJson(MarketingProduct $p, OrderPlacer $placer, bool $detail = false): array
    {
        $requiredFiles = $placer->requiredFileFields($p);
        $out = [
            'id' => $p->id,
            'title' => $p->title,
            'type' => $p->product_type,
            'type_name' => $p->type()->name ?? $p->product_type,
            'unit_price' => (int) $p->min_price,
            'quantity_mode' => $p->quantity_mode,                 // daily=단가×일수량×일수 | total=단가×수량
            'min_quantity' => $p->min_quantity,
            'max_quantity' => $p->max_quantity,
            'min_days' => $p->min_days,
            'fixed_quantity' => $p->fixed_quantity,               // 값이 있으면 수량 고정(입력 무시)
            'fixed_days' => $p->fixed_days,                       // 값이 있으면 기간 고정(종료일 자동)
            'earliest_start_date' => $p->earliestStartDate()->toDateString(),
            'orderable' => empty($requiredFiles),                 // false = 필수 파일 필드 → API 주문 불가
            'not_orderable_reason' => $requiredFiles ? '필수 파일 첨부 필드: '.implode(', ', $requiredFiles) : null,
        ];
        if ($detail) {
            $out['description'] = $p->description;
            $out['fields'] = $p->fields->where('is_active', true)->map(fn ($f) => [
                'key' => $f->field_key,
                'label' => $f->label,
                'type' => $f->field_type,                          // TEXT|TEXTAREA|URL|DATE|SELECT|MULTI_SELECT|NUMBER|FILE|IMAGE …
                'required' => (bool) $f->is_required,
                'help' => $f->help_text,
                'options' => $f->options_json,                     // SELECT 계열의 {value,label} 목록
                'contains' => $f->validation_json['contains'] ?? null,   // 입력에 반드시 포함돼야 하는 문자열
                'api_supported' => ! in_array($f->field_type, ['FILE', 'IMAGE'], true),
            ])->values();
        }

        return $out;
    }

    private function orderJson(MarketingOrder $o): array
    {
        return [
            'order_no' => $o->order_no,
            'status' => $o->status,
            'status_label' => MarketingOrder::STATUSES[$o->status] ?? $o->status,
            'product' => ['id' => $o->product?->id, 'title' => $o->product?->title],
            'quantity' => $o->quantity,
            'days' => $o->days,
            'unit_price' => (int) $o->unit_price,
            'discount_amount' => (int) $o->discount_amount,
            'total_price' => (int) $o->total_price,
            'fields' => $o->field_values ?: (object) [],
            'created_at' => $o->created_at->toIso8601String(),
        ];
    }
}
