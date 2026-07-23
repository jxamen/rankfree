<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 동적 주문 필드 정의 (13종 타입 + 조건부 노출). */
class ProductField extends Model
{
    /** 지원 필드 타입. */
    public const TYPES = [
        'TEXT' => '한 줄 텍스트',
        'TEXTAREA' => '여러 줄 텍스트',
        'URL' => 'URL',
        'NUMBER' => '숫자',
        'SELECT' => '단일 선택',
        'MULTI_SELECT' => '다중 선택',
        'TOGGLE' => '토글(예/아니오)',
        'DATE' => '날짜',
        'FILE' => '파일 업로드',
        'IMAGE' => '이미지 업로드',
        'ADDRESS' => '주소',
        'MISSION_OPTIONS' => '미션 옵션(체험단)',
        'TAGS' => '태그(다중 입력)',
    ];

    /**
     * 자동 채움 소스(2026-07-22) — 주문에 연결된 쇼핑 유입키워드 분석의 확장 수집값.
     * 내부(숨김) 필드가 외부 발주 전달용 값을 사람 손 없이 받게 한다.
     */
    public const AUTOFILL_SOURCES = [
        'core_keyword' => '핵심 키워드',
        'product_url' => '상품 URL',
        'product_id' => '상품 ID',
        'product_title' => '상품명(수집)',
        'mall_name' => '상점명(수집)',
        'product_price' => '상품 가격(수집)',
        'seller_tags' => '정답 태그 목록(수집)',
        'thumbnail_url' => '상품 이미지 URL(수집)',
        'short_url' => 'Short URL 목록(생성 후)',
    ];

    protected $fillable = [
        'product_id', 'group_id', 'field_key', 'field_type', 'label', 'placeholder', 'help_text',
        'is_required', 'is_hidden', 'autofill_source', 'default_value', 'options_json', 'validation_json', 'condition_json', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_required' => 'boolean', 'is_hidden' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer',
        'options_json' => 'array', 'validation_json' => 'array', 'condition_json' => 'array',
    ];

    /** SELECT/MULTI_SELECT 옵션 → [{value,label}] 정규화(문자열 배열·객체 배열 모두 허용). */
    public function options(): array
    {
        $out = [];
        foreach ((array) ($this->options_json ?? []) as $o) {
            if (is_array($o)) {
                $label = (string) ($o['label'] ?? $o['value'] ?? '');
                $out[] = ['value' => (string) ($o['value'] ?? $label), 'label' => $label];
            } elseif (is_string($o) && trim($o) !== '') {
                $out[] = ['value' => $o, 'label' => $o];
            }
        }

        return $out;
    }
}
