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

    protected $fillable = [
        'product_id', 'group_id', 'field_key', 'field_type', 'label', 'placeholder', 'help_text',
        'is_required', 'default_value', 'options_json', 'validation_json', 'condition_json', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_required' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer',
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
