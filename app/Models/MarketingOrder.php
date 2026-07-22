<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** 마케팅 상품 주문 — 동적 필드 입력값 + 수량·금액. */
class MarketingOrder extends Model
{
    /** 주문 상태 (코드 => 라벨). */
    public const STATUSES = [
        'pending' => '접수',
        'processing' => '진행중',
        'completed' => '완료',
        'canceled' => '취소',
    ];

    protected $fillable = [
        'order_no', 'product_id', 'user_id', 'quantity', 'days', 'field_values',
        'unit_price', 'total_price', 'status', 'orderer_name', 'orderer_contact',
        'user_coupon_id', 'discount_amount',
    ];

    protected $casts = [
        'field_values' => 'array',
        'quantity' => 'integer', 'days' => 'integer',
        'unit_price' => 'decimal:2', 'total_price' => 'decimal:2', 'discount_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $o) {
            if (! $o->order_no) {
                $o->order_no = 'MO'.now()->format('ymd').strtoupper(Str::random(6));
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(MarketingProduct::class, 'product_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(OrderDispatch::class, 'order_id');
    }

    /** 사용된 쿠폰 발급분(할인 적용 시). */
    public function userCoupon(): BelongsTo
    {
        return $this->belongsTo(UserCoupon::class, 'user_coupon_id');
    }

    /** 주문 취소·삭제 시 쿠폰 복원 — 사용 이력을 지워 다시 쓸 수 있게(만료됐으면 만료 상태로 돌아감). */
    public function restoreCoupon(): void
    {
        if ($this->user_coupon_id && $this->userCoupon) {
            $this->userCoupon->update(['used_at' => null, 'order_id' => null]);
        }
    }

    /** 이 주문에서 만든 쇼핑 노출 키워드 분석들 — 발주 시 분석의 Short URL 사용(2026-07-22). */
    public function shopKeywordAnalyses(): HasMany
    {
        return $this->hasMany(ShopKeywordAnalysis::class, 'marketing_order_id');
    }

    /**
     * 쇼핑 유입키워드 수집 대상 여부 + 재료 추출 — 주문 입력값(field_values)에서
     * 키워드와 스마트스토어/쇼핑 상품 URL 을 찾는다(표준 키 keyword·shop_url, 없으면 휴리스틱).
     *
     * @return array{keyword: string, url: string}|null
     */
    public function shopKeywordSource(): ?array
    {
        $fv = (array) $this->field_values;
        $keyword = (string) $this->keywordFromFields();

        $url = trim((string) ($fv['shop_url'] ?? ''));
        if (! preg_match('#^https?://#i', $url)) {
            $url = '';
            foreach ($fv as $v) {
                if (is_string($v) && preg_match('#https?://(smartstore|brand|shopping|search\.shopping)\.naver\.com/\S+#i', $v, $m)) {
                    $url = trim($m[0]);
                    break;
                }
            }
        }

        return ($keyword !== '' && $url !== '') ? ['keyword' => $keyword, 'url' => $url] : null;
    }

    /** 주문 입력값에서 키워드만 추출(표기용) — 표준 키 keyword, 없으면 키 이름 휴리스틱(keyword·키워드 포함). */
    public function keywordFromFields(): ?string
    {
        $fv = (array) $this->field_values;

        $keyword = trim((string) ($fv['keyword'] ?? ''));
        if ($keyword === '') {
            foreach ($fv as $k => $v) {
                if (is_string($v) && $v !== '' && (str_contains((string) $k, 'keyword') || str_contains((string) $k, '키워드'))) {
                    $keyword = trim($v);
                    break;
                }
            }
        }

        return $keyword !== '' ? $keyword : null;
    }
}
