<?php

namespace App\Domain\Keyword;

/**
 * 플레이스 키워드 → 지역 해석(22). "강남역 맛집" → region='강남역', region_type='hotplace'.
 * 지역 풀·업종 패턴은 hub:place-seed 와 **같은 소스**(place_keyword_matrix + regions_kr)를 쓰고,
 * 카테고리의 region_types 순서까지 시더와 동일하게 적용해 결과가 어긋나지 않게 한다.
 *
 * 용도: ① region 컬럼(2026_07_16_000030) 이전 발행분 백필  ② 발행 시 후보에 region 이 없을 때 폴백.
 */
class PlaceKeywordRegions
{
    /** @var array<string, array<string, true>>|null region_type => [지역명 => true] */
    private ?array $poolByType = null;

    /** @var array<string, array{region_types: list<string>, patterns: array<string, true>}>|null 카테고리명 => 정의 */
    private ?array $defs = null;

    /**
     * 키워드에서 지역을 해석한다. 패턴(업종어)까지 일치해야 인정 — 오탐 방지.
     *   resolve('강남역 맛집', '맛집·음식점') → ['region' => '강남역', 'region_type' => 'hotplace']
     *   resolve('강남 스타일', '맛집·음식점') → null   (스타일은 업종 패턴이 아님)
     *
     * @return array{region: string, region_type: string}|null
     */
    public function resolve(string $keyword, string $categoryName): ?array
    {
        $def = $this->defs()[$categoryName] ?? null;
        if (! $def) {
            return null;
        }
        $tokens = preg_split('/\s+/u', trim($keyword)) ?: [];
        if (count($tokens) < 2) {
            return null;
        }

        // 긴 지역명 우선("전주 한옥마을 맛집" → '전주 한옥마을')
        for ($i = count($tokens) - 1; $i >= 1; $i--) {
            $region = implode(' ', array_slice($tokens, 0, $i));
            $rest = implode(' ', array_slice($tokens, $i));
            if (! isset($def['patterns'][$rest])) {
                continue;
            }
            // 타입은 시더와 동일하게 카테고리의 region_types 순서로 결정(첫 매치)
            foreach ($def['region_types'] as $rt) {
                if (isset($this->poolByType()[$rt][$region])) {
                    return ['region' => $region, 'region_type' => $rt];
                }
            }
        }

        return null;
    }

    /** region_type => [지역명 => true]. 큐레이션 + 전국 행정구역(시군구→city, 읍면동→dong) — HubPlaceSeed 와 동일 병합. */
    private function poolByType(): array
    {
        if ($this->poolByType !== null) {
            return $this->poolByType;
        }
        $matrix = require database_path('data/place_keyword_matrix.php');
        $pools = [];
        foreach ((array) $matrix['regions'] as $type => $names) {
            $pools[$type] = array_fill_keys((array) $names, true);
        }
        $krPath = database_path('data/regions_kr.php');
        if (is_file($krPath)) {
            $kr = require $krPath;
            foreach ((array) ($kr['sgg'] ?? []) as $n) {
                $pools['city'][$n] = true;
            }
            foreach ((array) ($kr['emd'] ?? []) as $n) {
                $pools['dong'][$n] = true;
            }
        }

        return $this->poolByType = $pools;
    }

    /** 카테고리명 => region_types·패턴(플립). */
    private function defs(): array
    {
        if ($this->defs !== null) {
            return $this->defs;
        }
        $matrix = require database_path('data/place_keyword_matrix.php');
        $out = [];
        foreach ((array) $matrix['categories'] as $def) {
            $out[$def['name']] = [
                'region_types' => (array) $def['region_types'],
                'patterns' => array_fill_keys((array) $def['patterns'], true),
            ];
        }

        return $this->defs = $out;
    }
}
