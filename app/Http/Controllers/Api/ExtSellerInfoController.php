<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopSellerInfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Store seller business information parsed from the seller-info popup after captcha.
 * Upserts by channel_uid.
 *
 * POST /api/ext/seller-infos
 */
class ExtSellerInfoController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // 판매자정보 수집(영업·관리자 열람 전용 데이터)은 슈퍼관리자 대량 수집 플로 전용 —
        // 일반 확장 사용자 토큰으로는 저장하지 않는다(2026-07-22 확정: 대량 수집 계열은 super only).
        if (! $request->user()?->isSuperAdmin()) {
            return response()->json(['ok' => false, 'message' => '권한이 없습니다.'], 403);
        }

        $data = $request->validate([
            'store_id' => ['nullable', 'string', 'max:100'],
            'channel_uid' => ['required', 'string', 'max:120'],
            'channel_id' => ['nullable', 'string', 'max:120'],
            'biz_name' => ['nullable', 'string', 'max:200'],
            'representative' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:60'],
            'biz_reg_no' => ['nullable', 'string', 'max:40'],
            'mail_order_no' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'string', 'max:190'],
            'address' => ['nullable', 'string', 'max:1000'],
            'raw' => ['nullable', 'array'],
            'seller_info_url' => ['nullable', 'string', 'max:2000'],
        ]);

        $channelUid = trim((string) $data['channel_uid']);

        $row = ShopSellerInfo::updateOrCreate(
            ['channel_uid' => $channelUid],
            [
                'user_id' => $request->user()?->id,
                'store_id' => $this->nullableTrim($data['store_id'] ?? null),
                'channel_id' => $this->nullableTrim($data['channel_id'] ?? null),
                'biz_name' => $this->nullableTrim($data['biz_name'] ?? null),
                'representative' => $this->nullableTrim($data['representative'] ?? null),
                'customer_phone' => $this->nullableTrim($data['customer_phone'] ?? null),
                'biz_reg_no' => $this->nullableTrim($data['biz_reg_no'] ?? null),
                'mail_order_no' => $this->nullableTrim($data['mail_order_no'] ?? null),
                'email' => $this->nullableTrim($data['email'] ?? null),
                'address' => $this->nullableTrim($data['address'] ?? null),
                'raw' => $data['raw'] ?? null,
                'seller_info_url' => $this->nullableTrim($data['seller_info_url'] ?? null),
                'captured_at' => now(),
            ],
        );

        return response()->json([
            'ok' => true,
            'data' => [
                'id' => $row->id,
                'store_id' => $row->store_id,
                'channel_uid' => $row->channel_uid,
                'biz_name' => $row->biz_name,
                'representative' => $row->representative,
                'customer_phone' => $row->customer_phone,
                'biz_reg_no' => $row->biz_reg_no,
                'mail_order_no' => $row->mail_order_no,
                'email' => $row->email,
                'address' => $row->address,
                'captured_at' => $row->captured_at?->toDateTimeString(),
            ],
        ]);
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
