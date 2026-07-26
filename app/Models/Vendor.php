<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 외부 발주 업체 — API 호출 또는 구글시트 행 추가로 주문 전송. */
class Vendor extends Model
{
    public const CHANNELS = [
        'api' => 'API 호출',
        'gsheet' => '구글시트',
    ];

    /**
     * 랜딩 URL 배정 방식(2026-07-25) — 거래처마다 주문 받는 형태가 다르다.
     *  - group : 분석 링크 1개(키워드 묶음)를 회차마다 돌려 사용(기존 방식)
     *  - param : 파라미터 값이 바뀌는 링크를 사용 — 어떤 파라미터를 바꾸는지는 shop_param_keys 에 기록
     *  - fixed : 업체에 등록해둔 URL(shop_url_patterns)을 순서대로 그대로 사용 — 파라미터를 바꾸지 않는다
     * ⚠️ 쇼핑 주문 기준. 플레이스는 방식이 다를 수 있어 별도 설정(place_*)으로 분리한다.
     */
    public const LINK_MODES = [
        'group' => '분석 링크 순환 (링크 1개에 키워드 묶음)',
        'param' => '파라미터 값 변경 (지정한 파라미터가 바뀜)',
        'fixed' => '등록 URL 순서대로 (가공 없이 그대로)',
    ];

    protected $fillable = [
        'name', 'channel', 'api_url', 'api_method', 'api_headers', 'api_format',
        'gsheet_id', 'gsheet_tab', 'memo', 'is_active', 'weekend_batch_dispatch',
        'shop_link_mode', 'shop_url_patterns', 'shop_param_keys',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'weekend_batch_dispatch' => 'boolean',   // true=주말 미접수 업체 → 토·일·월 발주분을 직전 금요일에 몰아 자동 전송
        'shop_url_patterns' => 'array',          // 쇼핑 주문에 쓸 URL·형식 목록(입력 순서 = 사용 순서, 운영자가 직접 관리)
        'shop_param_keys' => 'array',            // 파라미터 값 변경 방식일 때 바꾸는 파라미터 이름 목록(설정 기록)
    ];

    public function productVendors(): HasMany
    {
        return $this->hasMany(ProductVendor::class);
    }

    /** api_headers JSON → 연관배열 (파싱 실패 시 빈 배열). */
    public function headers(): array
    {
        $h = json_decode((string) $this->api_headers, true);

        return is_array($h) ? $h : [];
    }
}
