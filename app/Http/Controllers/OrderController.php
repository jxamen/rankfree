<?php

namespace App\Http\Controllers;

use App\Domain\Place\PlaceRankChecker;
use App\Models\MarketingOrder;
use App\Models\MarketingProduct;
use App\Models\ProductType;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** 마케팅 상품 주문 — 콘솔 내 회원 전용 주문 페이지(order_token 기반). */
class OrderController extends Controller
{
    public function show(string $token)
    {
        $product = MarketingProduct::where('order_token', $token)->where('is_active', true)->firstOrFail();
        $product->load('fields', 'fieldGroups');
        $sched = $this->scheduleFields($product);
        $specialKeys = collect($sched)->filter()->pluck('field_key')->all();

        $infoFields = $product->fields->where('is_active', true)
            ->reject(fn ($f) => in_array($f->field_key, $specialKeys, true))->values();

        // 스텝 모드: 정보 필드를 그룹별 단계로 묶는다(그룹 정렬 순).
        $groupOrder = $product->fieldGroups->sortBy('sort_order')->values();
        $infoGroups = $infoFields
            ->groupBy('group_id')
            ->map(fn ($fields, $gid) => [
                'name' => $groupOrder->firstWhere('id', $gid)->name ?? '주문 정보',
                'sort' => $groupOrder->firstWhere('id', $gid)->sort_order ?? 999,
                'fields' => $fields->values(),
            ])
            ->sortBy('sort')->values();

        return view('order.show', [
            'product' => $product,
            // 셀프마케팅 카탈로그와 동일한 유형 탭(전체·블로그 리뷰·체험단…) 노출용
            'typeNames' => ProductType::orderBy('sort_order')->pluck('name', 'code'),
            'activeTypeCodes' => MarketingProduct::where('is_active', true)->distinct()->pluck('product_type'),
            'minDate' => $product->earliestStartDate()->toDateString(),
            'qtyField' => $sched['qty'],
            'startField' => $sched['start'],
            'endField' => $sched['end'],
            'infoFields' => $infoFields,
            'infoGroups' => $infoGroups,
            'stepMode' => $product->field_render_mode === 'step',
        ]);
    }

    public function store(Request $request, string $token)
    {
        $product = MarketingProduct::where('order_token', $token)->where('is_active', true)->firstOrFail();
        $product->load('fields');
        $minDate = $product->earliestStartDate()->toDateString();
        $sched = $this->scheduleFields($product);
        $user = $request->user();

        // 동적 필드 검증·수집 (일수량·시작일·종료일도 여기서 함께 수집됨. 파일은 public 디스크에 저장)
        $values = [];
        foreach ($product->fields->where('is_active', true) as $f) {
            $key = 'f_'.$f->field_key;
            if (in_array($f->field_type, ['FILE', 'IMAGE'], true)) {
                if ($request->hasFile($key)) {
                    $values[$f->field_key] = $request->file($key)->store('orders/'.$product->id, 'public');
                } elseif ($f->is_required) {
                    return back()->withInput()->withErrors([$key => "'{$f->label}' 파일을 첨부하세요."]);
                } else {
                    $values[$f->field_key] = null;
                }

                continue;
            }
            $val = $request->input($key);
            if ($f->is_required && (is_null($val) || $val === '' || $val === [])) {
                return back()->withInput()->withErrors([$key => "'{$f->label}' 항목을 입력하세요."]);
            }
            // 날짜: 접수 마감·진행 지연을 반영한 최소 시작일 이전 선택 불가
            if ($f->field_type === 'DATE' && is_string($val) && $val !== '' && $val < $minDate) {
                return back()->withInput()->withErrors([$key => "'{$f->label}' 은(는) {$minDate} 이후 날짜만 가능합니다."]);
            }
            // 플레이스 URL 필드는 실제 업종을 반영한 m.place 형태로 정규화(스마트플레이스 등록과 동일)
            if ($f->field_type === 'URL' && is_string($val) && trim($val) !== '') {
                try {
                    if ($clean = app(PlaceRankChecker::class)->cleanPlaceUrl($val)) {
                        $val = $clean;
                    }
                } catch (\Throwable $e) {
                    // 조회 실패 시 원본 유지
                }
            }
            // 필수 포함 값 — 관리자가 설정한 문자열이 입력에 없으면 지정한 안내 메시지로 반려 (URL 정규화 후 검사)
            $contains = trim((string) ($f->validation_json['contains'] ?? ''));
            if ($contains !== '' && is_string($val) && trim($val) !== '' && ! str_contains($val, $contains)) {
                $msg = trim((string) ($f->validation_json['contains_message'] ?? ''))
                    ?: "'{$f->label}' 항목에는 '{$contains}' 이(가) 포함되어야 합니다.";

                return back()->withInput()->withErrors([$key => $msg]);
            }
            $values[$f->field_key] = $val;
        }

        // 수량: 일수량 필드가 있으면 그 값, 없으면 기본 quantity 입력
        $qty = (int) ($sched['qty'] ? ($values[$sched['qty']->field_key] ?? 0) : $request->input('quantity'));
        if ($qty < $product->min_quantity || $qty > $product->max_quantity) {
            return back()->withInput()->withErrors(['quantity' => "수량은 {$product->min_quantity}~{$product->max_quantity} 사이여야 합니다."]);
        }

        // 기간: 시작일·종료일 필드가 있으면 그 일수, 없으면 기본 days(일)
        $days = 1;
        if ($product->quantity_mode === 'daily') {
            if ($sched['start'] && $sched['end']) {
                $s = $values[$sched['start']->field_key] ?? null;
                $e = $values[$sched['end']->field_key] ?? null;
                if (! $s || ! $e) {
                    return back()->withInput()->withErrors(['days' => '시작일과 종료일을 선택하세요.']);
                }
                if ($e < $s) {
                    return back()->withInput()->withErrors(['days' => '종료일은 시작일 이후여야 합니다.']);
                }
                $days = Carbon::parse($s)->diffInDays(Carbon::parse($e)) + 1;
            } else {
                $days = (int) $request->input('days', $product->min_days);
            }
            if ($days < $product->min_days) {
                return back()->withInput()->withErrors(['days' => "기간은 최소 {$product->min_days}일 이상이어야 합니다."]);
            }
        }

        $unit = (float) $product->min_price;
        $total = $unit * $qty * $days;

        $order = MarketingOrder::create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'quantity' => $qty,
            'days' => $product->quantity_mode === 'daily' ? $days : null,
            'field_values' => $values,
            'unit_price' => $unit,
            'total_price' => $total,
            'status' => 'pending',
            'orderer_name' => $user->name,
            'orderer_contact' => $user->email,
        ]);

        return redirect()->route('order.show', $token)->with('order_done', $order->order_no);
    }

    /** 붙여넣은 플레이스 URL → 실제 업종별 m.place URL 로 정규화(주문 폼 즉시 반영용). */
    public function resolvePlace(Request $request)
    {
        $url = (string) $request->input('url', '');
        try {
            $clean = app(PlaceRankChecker::class)->cleanPlaceUrl($url);
        } catch (\Throwable $e) {
            $clean = null;
        }

        return response()->json(['url' => $clean]);
    }

    /**
     * 상품의 수량·기간 시스템 필드(일수량·시작일·종료일)를 field_key 로 식별.
     * 없으면 null → 주문 페이지가 기본 수량/기간 입력으로 폴백한다.
     *
     * @return array{qty:?\App\Models\ProductField, start:?\App\Models\ProductField, end:?\App\Models\ProductField}
     */
    private function scheduleFields(MarketingProduct $product): array
    {
        $active = $product->fields->where('is_active', true);

        return [
            'qty' => $active->firstWhere('field_key', 'daily_qty'),
            'start' => $active->firstWhere('field_key', 'start_date'),
            'end' => $active->firstWhere('field_key', 'end_date'),
        ];
    }
}
