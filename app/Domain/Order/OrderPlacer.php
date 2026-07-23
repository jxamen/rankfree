<?php

namespace App\Domain\Order;

use App\Domain\Place\PlaceRankChecker;
use App\Models\MarketingOrder;
use App\Models\MarketingProduct;
use App\Models\User;
use App\Models\UserCoupon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 마케팅 상품 주문 생성 — 웹(OrderController)과 외부 API(Api\OrderApiController)가 공유하는 단일 로직.
 * 동적 필드 검증(필수·날짜·contains·플레이스 URL 정규화) → 수량/기간(고정 강제 포함) → 쿠폰 → 트랜잭션 생성.
 * 입력 오류는 전부 OrderInputException(field, message) 로 던진다.
 *
 * ⚠️ 파일(FILE/IMAGE) 값은 호출자가 처리해 저장 경로를 fieldInput 에 넣어 전달한다(웹 업로드 전용).
 *    API 는 파일 필드를 지원하지 않는다 — 필수 파일 필드가 있는 상품은 API 주문 불가로 안내.
 */
class OrderPlacer
{
    /**
     * @param  array<string, mixed>  $fieldInput  field_key => 입력값 (파일 필드는 저장 경로 또는 null)
     * @param  array{quantity?: mixed, days?: mixed, user_coupon_id?: mixed}  $opts
     *
     * @throws OrderInputException
     */
    public function place(User $user, MarketingProduct $product, array $fieldInput, array $opts = []): MarketingOrder
    {
        $product->loadMissing('fields');
        $minDate = $product->earliestStartDate()->toDateString();
        $sched = $this->scheduleFields($product);

        // ── 동적 필드 검증·정규화 ─────────────────────────────────────────
        $values = [];
        foreach ($product->fields->where('is_active', true) as $f) {
            // 숨김(내부) 필드 — 고객 입력·검증 없이 기본값만 시드. 실값은 유입키워드 수집(autofill)·관리자 입력으로 채운다.
            if ($f->is_hidden) {
                $values[$f->field_key] = ($f->default_value !== null && $f->default_value !== '') ? $f->default_value : null;

                continue;
            }
            $val = $fieldInput[$f->field_key] ?? null;

            if (in_array($f->field_type, ['FILE', 'IMAGE'], true)) {
                if (($val === null || $val === '') && $f->is_required) {
                    throw new OrderInputException('f_'.$f->field_key, "'{$f->label}' 파일을 첨부하세요.");
                }
                $values[$f->field_key] = $val ?: null;

                continue;
            }

            if ($f->is_required && (is_null($val) || $val === '' || $val === [])) {
                throw new OrderInputException('f_'.$f->field_key, "'{$f->label}' 항목을 입력하세요.");
            }
            // 날짜: 접수 마감·진행 지연을 반영한 최소 시작일 이전 선택 불가
            if ($f->field_type === 'DATE' && is_string($val) && $val !== '' && $val < $minDate) {
                throw new OrderInputException('f_'.$f->field_key, "'{$f->label}' 은(는) {$minDate} 이후 날짜만 가능합니다.");
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

                throw new OrderInputException('f_'.$f->field_key, $msg);
            }
            // 금지 포함 값 — 설정 문자열이 입력에 있으면 반려(엉뚱한 URL·값 실수 접수 방지)
            $notContains = trim((string) ($f->validation_json['not_contains'] ?? ''));
            if ($notContains !== '' && is_string($val) && trim($val) !== '' && str_contains($val, $notContains)) {
                $msg = trim((string) ($f->validation_json['not_contains_message'] ?? ''))
                    ?: "'{$f->label}' 항목에는 '{$notContains}' 이(가) 포함될 수 없습니다.";

                throw new OrderInputException('f_'.$f->field_key, $msg);
            }
            $values[$f->field_key] = $val;
        }

        // ── 수량: 고정 수량 상품이면 입력과 무관하게 그 값(패키지 판매), 아니면 입력값 + 범위 검증 ──
        if ($product->fixed_quantity) {
            $qty = $product->fixed_quantity;
            if ($sched['qty']) {
                $values[$sched['qty']->field_key] = (string) $qty;   // 저장되는 입력값도 고정값으로 통일
            }
        } else {
            $qty = (int) ($sched['qty'] ? ($values[$sched['qty']->field_key] ?? 0) : ($opts['quantity'] ?? 0));
            if ($qty < $product->min_quantity || $qty > $product->max_quantity) {
                throw new OrderInputException('quantity', "수량은 {$product->min_quantity}~{$product->max_quantity} 사이여야 합니다.");
            }
        }

        // ── 기간: 고정 기간이면 시작일만 받고 종료일·일수는 서버가 확정, 아니면 입력 방식 ──
        $days = 1;
        if ($product->quantity_mode === 'daily') {
            if ($product->fixed_days) {
                $days = $product->fixed_days;
                if ($sched['start']) {
                    $s = $values[$sched['start']->field_key] ?? null;
                    if (! $s) {
                        throw new OrderInputException('days', '시작일을 선택하세요.');
                    }
                    if ($sched['end']) {
                        // 종료일은 제출값을 믿지 않고 시작일 + 고정기간 - 1 로 재계산
                        $values[$sched['end']->field_key] = Carbon::parse($s)->addDays($days - 1)->toDateString();
                    }
                }
            } elseif ($sched['start'] && $sched['end']) {
                $s = $values[$sched['start']->field_key] ?? null;
                $e = $values[$sched['end']->field_key] ?? null;
                if (! $s || ! $e) {
                    throw new OrderInputException('days', '시작일과 종료일을 선택하세요.');
                }
                if ($e < $s) {
                    throw new OrderInputException('days', '종료일은 시작일 이후여야 합니다.');
                }
                $days = Carbon::parse($s)->diffInDays(Carbon::parse($e)) + 1;
            } else {
                $days = (int) ($opts['days'] ?? $product->min_days);
            }
            if (! $product->fixed_days && $days < $product->min_days) {
                throw new OrderInputException('days', "기간은 최소 {$product->min_days}일 이상이어야 합니다.");
            }
        }

        $unit = (float) $product->min_price;
        $gross = $unit * $qty * $days;

        // ── 쿠폰(26) — 본인 발급분·사용 가능·상품 적용 가능 검증 후 서버에서 할인 재계산 ──
        $userCoupon = null;
        $discount = 0;
        if ($ucId = ($opts['user_coupon_id'] ?? null)) {
            $userCoupon = UserCoupon::with('coupon')->whereKey($ucId)->where('user_id', $user->id)->first();
            if (! $userCoupon || ! $userCoupon->isUsable()) {
                throw new OrderInputException('user_coupon_id', '사용할 수 없는 쿠폰입니다(만료·사용됨·중지).');
            }
            if (! $userCoupon->coupon->appliesTo($product->id)) {
                throw new OrderInputException('user_coupon_id', '이 상품에는 사용할 수 없는 쿠폰입니다.');
            }
            $discount = $userCoupon->coupon->discountFor($gross);
            if ($discount < 1) {
                throw new OrderInputException('user_coupon_id', '최소 주문 금액('.number_format((float) $userCoupon->coupon->min_order_amount).'원) 이상부터 사용할 수 있는 쿠폰입니다.');
            }
        }

        return DB::transaction(function () use ($product, $user, $qty, $days, $values, $unit, $gross, $discount, $userCoupon) {
            // 동시 제출로 같은 쿠폰이 두 번 쓰이지 않게 잠금 후 미사용 재확인
            if ($userCoupon) {
                $locked = UserCoupon::whereKey($userCoupon->id)->whereNull('used_at')->lockForUpdate()->first();
                if (! $locked) {
                    throw new OrderInputException('user_coupon_id', '이미 사용된 쿠폰입니다.');
                }
            }

            $order = MarketingOrder::create([
                'product_id' => $product->id,
                'user_id' => $user->id,
                'quantity' => $qty,
                'days' => $product->quantity_mode === 'daily' ? $days : null,
                'field_values' => $values,
                'unit_price' => $unit,
                'total_price' => max(0, $gross - $discount),
                'status' => 'pending',
                'orderer_name' => $user->name,
                'orderer_contact' => $user->email,
                'user_coupon_id' => $userCoupon?->id,
                'discount_amount' => $discount,
            ]);
            $userCoupon?->update(['used_at' => now(), 'order_id' => $order->id]);

            return $order;
        });
    }

    /** 필수 파일 필드 목록 — 있으면 API 주문 불가(파일 업로드는 웹 전용). */
    public function requiredFileFields(MarketingProduct $product): array
    {
        return $product->fields->where('is_active', true)
            ->filter(fn ($f) => in_array($f->field_type, ['FILE', 'IMAGE'], true) && $f->is_required)
            ->pluck('label')->values()->all();
    }

    /**
     * 상품의 수량·기간 시스템 필드(일수량·시작일·종료일)를 field_key 로 식별.
     *
     * @return array{qty: ?\App\Models\ProductField, start: ?\App\Models\ProductField, end: ?\App\Models\ProductField}
     */
    public function scheduleFields(MarketingProduct $product): array
    {
        $active = $product->fields->where('is_active', true);

        return [
            'qty' => $active->firstWhere('field_key', 'daily_qty'),
            'start' => $active->firstWhere('field_key', 'start_date'),
            'end' => $active->firstWhere('field_key', 'end_date'),
        ];
    }
}
