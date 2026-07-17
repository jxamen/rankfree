<?php

namespace App\Domain\Keyword;

/**
 * 플레이스 지역 계층(22) — /keywords/place 의 3단계 드릴다운(시/도 → 시/군/구 → 동·상권).
 * 문서의 region 은 '가경동'·'강남역'처럼 **한 단계 문자열**이라, 소속을 두 소스에서 되짚는다.
 *   ① 행정구역: database/data/regions_kr.php 의 parent (지역명 => [시도, 시군구]) — 자동 생성
 *   ② 상권·여행지: place_keyword_matrix.php 의 region_parents (강남역 => [서울, 강남구]) — 큐레이션
 * 매핑이 없으면 '기타'로 모은다(빈 화면보다 낫다).
 *
 * ⚠️ 같은 동 이름이 여러 시군구에 있으면(regions_kr 의 ambiguous) 첫 소속으로 표시된다.
 */
class PlaceRegionTree
{
    public const ETC = '기타';

    /** @var array<string, array{0:string,1:string}>|null 지역명 => [시도, 시군구] */
    private ?array $parents = null;

    /** @return array{0:string,1:string} [시도, 시군구] — 미매핑은 [기타, 기타] */
    public function parentOf(string $region): array
    {
        return $this->parents()[$region] ?? [self::ETC, self::ETC];
    }

    /**
     * 지역별 문서 수(region => count)를 3단계 트리로 접는다.
     *
     * @param  iterable<string, int>  $regionCounts
     * @return array{
     *   sido: array<string,int>,
     *   sgg: array<string, array<string,int>>,
     *   leaf: array<string, array<string, array<string,int>>>
     * } sido[시도]=합계 · sgg[시도][시군구]=합계 · leaf[시도][시군구][지역명]=문서수
     */
    public function group(iterable $regionCounts): array
    {
        $out = ['sido' => [], 'sgg' => [], 'leaf' => []];
        foreach ($regionCounts as $region => $count) {
            [$sido, $sgg] = $this->parentOf((string) $region);
            $count = (int) $count;
            $out['sido'][$sido] = ($out['sido'][$sido] ?? 0) + $count;
            $out['sgg'][$sido][$sgg] = ($out['sgg'][$sido][$sgg] ?? 0) + $count;
            $out['leaf'][$sido][$sgg][(string) $region] = $count;
        }

        // 문서 많은 순 — '기타'는 항상 끝으로
        $sortDesc = function (array &$a) {
            arsort($a);
            if (isset($a[self::ETC])) {
                $etc = $a[self::ETC];
                unset($a[self::ETC]);
                $a[self::ETC] = $etc;
            }
        };
        $sortDesc($out['sido']);
        foreach ($out['sgg'] as &$byCat) {
            $sortDesc($byCat);
        }
        unset($byCat);
        foreach ($out['leaf'] as &$bySgg) {
            foreach ($bySgg as &$leaves) {
                arsort($leaves);
            }
        }

        return $out;
    }

    /** 선택된 시도·시군구에 속하는 지역명 목록 — 문서 쿼리(whereIn region)용. */
    public function regionsIn(array $grouped, ?string $sido, ?string $sgg): array
    {
        if ($sido === null) {
            return [];
        }
        $bySgg = $grouped['leaf'][$sido] ?? [];
        if ($sgg !== null) {
            return array_keys($bySgg[$sgg] ?? []);
        }

        return array_keys(array_merge(...array_values($bySgg) ?: [[]]));
    }

    private function parents(): array
    {
        if ($this->parents !== null) {
            return $this->parents;
        }
        $out = [];
        $kr = database_path('data/regions_kr.php');
        if (is_file($kr)) {
            foreach ((array) ((require $kr)['parent'] ?? []) as $name => $p) {
                $out[(string) $name] = [(string) $p[0], (string) $p[1]];
            }
        }
        // 상권·여행지 큐레이션이 행정구역 매핑을 덮어쓴다(강남역·홍대 등은 행정동이 아니다)
        $matrix = require database_path('data/place_keyword_matrix.php');
        foreach ((array) ($matrix['region_parents'] ?? []) as $name => $p) {
            $out[(string) $name] = [(string) $p[0], (string) $p[1]];
        }

        return $this->parents = $out;
    }
}
