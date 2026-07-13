<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** API 키 일자별 호출 수 (일일 한도 검사용). */
class ApiKeyUsage extends Model
{
    public $timestamps = false;

    protected $fillable = ['api_key_id', 'used_date', 'count'];

    protected $casts = [
        // date:Y-m-d 고정 — 'date' 캐스트는 시간까지 저장돼 updateOrCreate 조회가 빗나간다 (place_rank_records 전례)
        'used_date' => 'date:Y-m-d',
        'count' => 'integer',
    ];

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }
}
