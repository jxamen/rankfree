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

    protected $fillable = [
        'name', 'channel', 'api_url', 'api_method', 'api_headers', 'api_format',
        'gsheet_id', 'gsheet_tab', 'memo', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

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
