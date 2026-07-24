<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * 무통장 입금 계좌 — 운영자가 환경설정(결제 탭)에 저장한 은행/계좌번호/예금주.
 * 주문 완료 화면에서 입금 안내로 노출한다. 값은 AppSetting 에 암호화 저장.
 */
class BankAccount
{
    /** 선택 가능한 은행 목록(표시명). 필요 시 여기만 넣고 빼면 된다. */
    public const BANKS = [
        'KB국민은행', '신한은행', '우리은행', '하나은행', 'NH농협은행', 'IBK기업은행',
        '카카오뱅크', '케이뱅크', '토스뱅크', 'SC제일은행', '한국씨티은행',
        '부산은행', 'iM뱅크(대구)', '광주은행', '전북은행', '경남은행', '제주은행',
        '새마을금고', '신협', '우체국', 'KDB산업은행', 'Sh수협은행', 'SBI저축은행',
    ];

    /**
     * 무통장 입금 계좌 설정. 미설정 항목은 빈 문자열.
     *
     * @return array{bank:string, account:string, holder:string}
     */
    public static function info(): array
    {
        return [
            'bank' => (string) AppSetting::read('bank.name', ''),
            'account' => (string) AppSetting::read('bank.account', ''),
            'holder' => (string) AppSetting::read('bank.holder', ''),
        ];
    }

    /** 계좌 안내가 가능한 상태(은행 + 계좌번호가 모두 설정)인지. */
    public static function isConfigured(): bool
    {
        $info = self::info();

        return $info['bank'] !== '' && $info['account'] !== '';
    }
}
