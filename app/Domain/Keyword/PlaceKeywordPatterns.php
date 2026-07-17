<?php

namespace App\Domain\Keyword;

use App\Models\AppSetting;

/**
 * 플레이스 업종별 패턴("맛집"·"곱창"·"치과" …) 단일 소스.
 * 관리자 환경설정(플레이스 패턴 탭)에서 넣고 뺄 수 있게 DB(AppSetting: place.patterns)에 저장하고,
 * 값이 없으면 database/data/place_keyword_matrix.php 의 기본 패턴을 쓴다.
 * hub:place-seed 가 지역 × 패턴 조합을 만들 때 이 목록을 사용한다.
 */
class PlaceKeywordPatterns
{
    private const KEY = 'place.patterns';

    /**
     * 업종 키 → ['name' => 표시명, 'patterns' => [...]].
     * 우선순위: ① DB(관리자가 넣고 뺀 값) ② place_patterns_verified.php(검색량 검증 통과분) ③ 매트릭스 기본값.
     */
    public function all(): array
    {
        $matrix = require database_path('data/place_keyword_matrix.php');
        $vpath = database_path('data/place_patterns_verified.php');
        $verified = is_file($vpath) ? (array) require $vpath : [];
        $saved = AppSetting::readJson(self::KEY);   // ['restaurant' => [...], ...]

        $out = [];
        foreach ($matrix['categories'] as $key => $def) {
            $out[$key] = [
                'name' => $def['name'],
                'patterns' => array_values(array_unique(array_filter(
                    $saved[$key] ?? $verified[$key] ?? $def['patterns'],
                    fn ($p) => trim((string) $p) !== ''
                ))),
            ];
        }

        return $out;
    }

    /** 업종의 패턴 목록(시딩용). */
    public function for(string $categoryKey): array
    {
        return $this->all()[$categoryKey]['patterns'] ?? [];
    }

    /** 저장 — 업종별 패턴 배열. 빈 업종은 기본값으로 되돌리지 않고 그대로(빈 목록) 저장한다. */
    public function save(array $byCategory): void
    {
        $matrix = require database_path('data/place_keyword_matrix.php');
        $clean = [];
        foreach ($matrix['categories'] as $key => $def) {
            if (! array_key_exists($key, $byCategory)) {
                continue;
            }
            $clean[$key] = array_values(array_unique(array_filter(
                array_map(fn ($p) => trim((string) $p), (array) $byCategory[$key]),
                fn ($p) => $p !== ''
            )));
        }
        AppSetting::write(self::KEY, json_encode($clean, JSON_UNESCAPED_UNICODE));
    }

    /** 콤마/줄바꿈 구분 문자열 → 패턴 배열. */
    public static function parse(string $raw): array
    {
        return array_values(array_unique(array_filter(
            array_map('trim', preg_split('/[,\r\n]+/u', $raw) ?: []),
            fn ($p) => $p !== ''
        )));
    }
}
