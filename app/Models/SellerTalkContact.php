<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 셀러력 수집 중 확보한 판매자 톡톡/스토어 연락 식별자(마케팅 리드).
 * 조회는 슈퍼어드민 전용(Admin\TalkContactController).
 */
class SellerTalkContact extends Model
{
    protected $fillable = [
        'keyword', 'mall_name', 'rank', 'talk_id', 'collected_by', 'collected_at',
    ];

    protected $casts = [
        'rank' => 'integer',
        'collected_at' => 'datetime',
    ];

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }
}
