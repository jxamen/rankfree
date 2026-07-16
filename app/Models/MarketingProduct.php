<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** 마케팅 상품 — 폼 빌더로 정의한 판매 상품(가격·수량·스케줄 + 동적 주문 필드). */
class MarketingProduct extends Model
{
    protected $fillable = [
        'product_type', 'sub_type_code', 'title', 'description',
        'base_cost', 'min_price', 'min_quantity', 'max_quantity', 'min_days',
        'quantity_mode', 'min_daily_quantity', 'field_render_mode', 'default_fulfillment',
        'daily_cutoff_hour', 'process_weekends', 'process_holidays', 'processing_lag_days',
        'order_token', 'is_active', 'created_by',
    ];

    protected $casts = [
        'base_cost' => 'decimal:2',
        'min_price' => 'decimal:2',
        'default_fulfillment' => 'decimal:2',
        'min_quantity' => 'integer', 'max_quantity' => 'integer', 'min_days' => 'integer',
        'min_daily_quantity' => 'integer', 'daily_cutoff_hour' => 'integer', 'processing_lag_days' => 'integer',
        'process_weekends' => 'boolean', 'process_holidays' => 'boolean', 'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $p) {
            if (! $p->order_token) {
                $p->order_token = Str::random(24);
            }
        });
    }

    public function fields(): HasMany
    {
        return $this->hasMany(ProductField::class, 'product_id')->orderBy('sort_order');
    }

    public function fieldGroups(): HasMany
    {
        return $this->hasMany(ProductFieldGroup::class, 'product_id')->orderBy('sort_order');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(MarketingOrder::class, 'product_id');
    }

    /** 외부 발주 업체 배분 설정. */
    public function vendorAllocations(): HasMany
    {
        return $this->hasMany(ProductVendor::class, 'product_id')->orderBy('sort_order');
    }

    public function type()
    {
        return ProductType::where('code', $this->product_type)->first();
    }

    /** 주문 페이지 공개 URL. */
    public function orderUrl(): string
    {
        return route('order.show', $this->order_token);
    }

    /**
     * 주문 가능한 가장 빠른 시작일(진행 시작일).
     *  1) 오늘이 진행일이고 접수 마감 시각이 지났으면 익일부터(마감은 영업일에만 의미).
     *  2) 진행 시작 지연(영업일)만큼 진행일을 세며 전진(주말·공휴일 스킵은 지연일에 포함되지 않음).
     *  3) 시작일 자체도 진행일이어야 함(주말·공휴일이면 다음 진행일).
     * 예) 주말진행X·지연2영업일: 일요일 주문 → 월(접수)·화(진행 시작). 금 15:01(마감15) → 월(접수)·화(진행).
     * 공휴일 캘린더 미보유 — 현재는 주말 기준까지 반영.
     */
    public function earliestStartDate(?\DateTimeInterface $now = null): \Illuminate\Support\Carbon
    {
        $now = $now ? \Illuminate\Support\Carbon::instance($now) : now();
        $day = $now->copy()->startOfDay();

        // 1) 오늘이 진행일인데 접수 마감 시각이 지났으면 익일부터(주말·공휴일엔 마감 개념 없음)
        if ($this->isProcessingDay($day) && $this->daily_cutoff_hour !== null && $now->hour >= (int) $this->daily_cutoff_hour) {
            $day->addDay();
        }

        // 2) 진행 시작 지연(영업일)만큼 전진 — 진행일만 카운트(주말·공휴일 스킵은 지연에 미포함)
        $remaining = max(0, (int) $this->processing_lag_days);
        while ($remaining > 0) {
            $day->addDay();
            if ($this->isProcessingDay($day)) {
                $remaining--;
            }
        }

        // 3) 시작일은 진행일이어야 함(주말·공휴일이면 다음 진행일)
        while (! $this->isProcessingDay($day)) {
            $day->addDay();
        }

        return $day;
    }

    /** 해당 날짜가 진행(처리) 가능한 날인지(주말·공휴일 진행 여부 기준). */
    public function isProcessingDay(\Illuminate\Support\Carbon $d): bool
    {
        if (! $this->process_weekends && $d->isWeekend()) {
            return false;
        }

        // 공휴일 캘린더 미보유 — process_holidays 는 추후 캘린더 연동 시 반영
        return true;
    }
}
