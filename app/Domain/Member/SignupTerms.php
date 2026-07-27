<?php

namespace App\Domain\Member;

use App\Models\AppSetting;

/**
 * 회원가입 약관(2026-07-27) — 환경설정에서 항목·본문·필수여부를 관리하고 가입 폼이 그대로 렌더한다.
 *
 * 저장 형식: AppSetting 'terms.items' = [{key,title,body,required,is_active}, …] (배열 순서 = 노출 순서)
 * 항목을 늘리고 싶으면 환경설정에서 추가하면 되고, 코드 수정이 필요 없다.
 */
class SignupTerms
{
    public const SETTING_KEY = 'terms.items';

    /** 미설정 상태에서 쓰는 기본 약관 — 관리자가 환경설정에서 본문을 다듬어 쓴다. */
    public static function defaults(): array
    {
        return [
            [
                'key' => 'service',
                'title' => '이용약관 동의',
                'required' => true,
                'is_active' => true,
                'body' => "제1조(목적)\n이 약관은 랭크프리(이하 \"회사\")가 제공하는 네이버 플레이스·쇼핑 순위 분석 및 마케팅 관련 서비스(이하 \"서비스\")의 이용조건과 절차, 회사와 회원의 권리·의무 및 책임사항을 규정함을 목적으로 합니다.\n\n제2조(회원가입)\n① 회원가입은 이용자가 약관에 동의하고 회사가 정한 절차에 따라 가입을 신청하면 회사가 이를 승낙함으로써 체결됩니다.\n② 회원은 가입 시 정확한 정보를 제공해야 하며, 허위 정보로 인한 불이익은 회원이 부담합니다.\n\n제3조(서비스의 제공 및 변경)\n① 회사는 순위 분석, 키워드 분석, 리포트 제공 등의 서비스를 제공합니다.\n② 회사는 서비스의 내용을 변경할 수 있으며, 변경 시 사전에 공지합니다.\n\n제4조(회원의 의무)\n회원은 서비스를 이용하며 관계 법령과 이 약관을 준수해야 하며, 타인의 정보를 도용하거나 서비스 운영을 방해해서는 안 됩니다.\n\n제5조(계약 해지)\n회원은 언제든지 서비스 내 기능 또는 고객센터를 통해 이용계약 해지를 요청할 수 있습니다.\n\n제6조(면책)\n회사는 네이버 등 외부 플랫폼의 정책·알고리즘 변경으로 인한 분석 결과의 변동에 대해 책임을 지지 않습니다.",
            ],
            [
                'key' => 'marketing',
                'title' => '마케팅·이벤트 정보 수신 동의',
                'required' => true,
                'is_active' => true,
                'body' => "회사는 아래와 같이 마케팅 및 이벤트 안내를 위해 회원의 개인정보를 이용하고 광고성 정보를 전송합니다.\n\n1. 수신 목적\n신규 서비스 및 기능 안내, 이벤트·프로모션·할인 혜택 안내, 맞춤형 마케팅 정보 제공\n\n2. 전송 수단\n문자메시지(SMS/LMS), 카카오톡, 알림톡, 이메일, 전화(유선 안내)\n\n3. 이용 항목\n이름, 휴대전화번호, 이메일 주소, 서비스 이용 이력\n\n4. 보유 및 이용 기간\n회원 탈퇴 시 또는 수신 동의 철회 시까지\n\n5. 동의 철회\n회원은 언제든지 고객센터 또는 수신 거부 절차를 통해 동의를 철회할 수 있으며, 철회 시 마케팅 정보 전송이 중단됩니다. 다만 서비스 이용에 필요한 필수 안내(계약·결제·중요 공지)는 동의 여부와 관계없이 발송됩니다.",
            ],
            [
                'key' => 'third_party',
                'title' => '제3자 마케팅 정보 제공 동의',
                'required' => true,
                'is_active' => true,
                'body' => "회사는 마케팅 서비스 중개 및 제휴 혜택 제공을 위해 아래와 같이 개인정보를 제3자에게 제공합니다.\n\n1. 제공받는 자\n회사와 제휴한 마케팅 서비스 수행 업체 및 광고주\n\n2. 제공 목적\n마케팅 서비스 상담·안내, 제휴 이벤트 및 혜택 안내, 광고성 정보 전송\n\n3. 제공 항목\n이름, 휴대전화번호, 이메일 주소\n\n4. 제공받는 자의 이용 수단\n문자메시지(SMS/LMS), 카카오톡, 알림톡, 이메일, 전화\n\n5. 보유 및 이용 기간\n제공 목적 달성 시 또는 회원의 동의 철회 시까지\n\n6. 동의 철회\n회원은 언제든지 고객센터를 통해 제3자 제공 동의를 철회할 수 있습니다.",
            ],
        ];
    }

    /**
     * 설정에 저장된 약관 항목(없으면 기본값). 활성 항목만·순서 유지.
     *
     * @return list<array{key:string,title:string,body:string,required:bool,is_active:bool}>
     */
    public static function active(): array
    {
        return array_values(array_filter(static::all(), fn ($t) => ! empty($t['is_active'])));
    }

    /** 저장된 전체 항목(관리 화면용 — 비활성 포함). */
    public static function all(): array
    {
        $rows = AppSetting::readJson(self::SETTING_KEY);
        if ($rows === []) {
            return static::defaults();
        }

        return array_values(array_map(fn ($t) => [
            'key' => (string) ($t['key'] ?? ''),
            'title' => (string) ($t['title'] ?? ''),
            'body' => (string) ($t['body'] ?? ''),
            'required' => (bool) ($t['required'] ?? false),
            'is_active' => (bool) ($t['is_active'] ?? true),
        ], array_filter($rows, fn ($t) => trim((string) ($t['key'] ?? '')) !== '')));
    }

    /** 가입 시 반드시 동의해야 하는 항목 키. */
    public static function requiredKeys(): array
    {
        return array_values(array_map(
            fn ($t) => $t['key'],
            array_filter(static::active(), fn ($t) => ! empty($t['required'])),
        ));
    }

    /** 저장 — 빈 키·제목은 버리고 순서 그대로 보관. */
    public static function save(array $rows): void
    {
        $clean = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row['key'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));
            if ($key === '' || $title === '') {
                continue;
            }
            $clean[] = [
                'key' => $key,
                'title' => $title,
                'body' => (string) ($row['body'] ?? ''),
                'required' => ! empty($row['required']),
                'is_active' => ! empty($row['is_active']),
            ];
        }

        AppSetting::write(self::SETTING_KEY, json_encode($clean, JSON_UNESCAPED_UNICODE));
    }
}
