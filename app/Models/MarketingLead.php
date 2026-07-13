<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 마케팅 리드 — 분석 리포트의 "순위 상승 문의하기" 등으로 접수된 상담 문의.
 * 비로그인(공개 공유 페이지)에서도 남길 수 있어 user_id 는 nullable.
 */
class MarketingLead extends Model
{
    /** 처리 상태 (코드 => 라벨). */
    public const STATUSES = [
        'new' => '신규',
        'contacted' => '상담중',
        'done' => '완료',
        'spam' => '스팸',
    ];

    /** 유입 지점 (코드 => 라벨). 알 수 없는 값은 'other' 로 정규화. */
    public const SOURCES = [
        'market_seasonal' => '시장분석·시즌',
        'market' => '시장분석',
        'product' => '상품분석',
        'keyword' => '키워드분석',
        'seller_power' => '셀러력',
        'other' => '기타',
    ];

    protected $fillable = [
        'user_id', 'name', 'phone', 'keyword', 'source', 'interest', 'message', 'meta', 'status', 'ip',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? $this->source;
    }
}
