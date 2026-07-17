<?php

/**
 * 전국 행정동 GeoJSON(vuski/admdongkor adm_nm) → database/data/regions_kr.php 생성기.
 *   php scripts/generate-regions-kr.php <HangJeongDong.geojson 경로>
 *
 * 출력:
 *   sgg    : 시군구 지역명 목록(hub:place-seed 조합 풀) — '강남구'·'강남'·'수원'·'양평군' 등 검색형 변형 포함
 *   emd    : 읍면동 지역명 목록(조합 풀) — '역삼1동'→'역삼동' 정규화
 *   parent : 지역명 => [시/도 축약, 시/군/구]  ← /keywords/place 3단계 드릴다운(시도>시군구>동)의 소재
 *   ambiguous : 같은 동 이름이 여러 시군구에 있는 목록(첫 소속으로 매핑됨 — 표시상 주의)
 */
if (! isset($argv[1]) || ! is_file($argv[1])) {
    fwrite(STDERR, "사용법: php scripts/generate-regions-kr.php <geojson>\n");
    exit(1);
}

/** 시도 정식명 → 축약(파일 전체에서 이 17개만 쓴다). */
$SIDO = [
    '서울특별시' => '서울', '부산광역시' => '부산', '대구광역시' => '대구', '인천광역시' => '인천',
    '광주광역시' => '광주', '대전광역시' => '대전', '울산광역시' => '울산', '세종특별자치시' => '세종',
    '경기도' => '경기', '강원도' => '강원', '강원특별자치도' => '강원',
    '충청북도' => '충북', '충청남도' => '충남',
    '전라북도' => '전북', '전북특별자치도' => '전북', '전라남도' => '전남',
    '경상북도' => '경북', '경상남도' => '경남', '제주특별자치도' => '제주',
];

preg_match_all('/"adm_nm": *"([^"]+)"/u', (string) file_get_contents($argv[1]), $m);

$emd = [];        // 동 이름 => true
$sgg = [];        // 시군구 지역명(변형 포함) => true
$parent = [];     // 지역명 => [시도축약, 시군구]
$emdOwners = [];  // 동 이름 => [소속 시군구, …] (중복 판정용)

$put = function (string $name, string $sido, string $sggName) use (&$parent) {
    if ($name === '' || isset($parent[$name])) {
        return; // 먼저 등장한 소속 유지(결정적)
    }
    $parent[$name] = [$sido, $sggName];
};

foreach (array_unique($m[1]) as $adm) {
    $parts = preg_split('/\s+/u', trim($adm));
    if (count($parts) < 2) {
        continue;
    }
    $sidoFull = array_shift($parts);
    $sido = $SIDO[$sidoFull] ?? null;
    if (! $sido) {
        continue;
    }
    $name = array_pop($parts);   // 읍면동
    $middle = $parts;            // 시군구(0~2 토큰, 병합형 "청주시흥덕구" 포함)

    // ── 시군구 표시명 결정: 도 소속은 시/군, 광역시는 구 ──
    $sggName = $sido.'시';       // 세종처럼 시군구가 없는 경우
    $variants = [];              // 이 행정동이 속한 시군구의 지역명 변형(조합 풀용)
    foreach ($middle as $t) {
        if (preg_match('/^(.+?)시(.+?구)$/u', $t, $mm)) {          // 청주시흥덕구 → 청주시 + 흥덕구
            $sggName = $mm[1].'시';
            $variants[] = $mm[1];        // '청주'
            $variants[] = $mm[2];        // '흥덕구'
        } elseif (preg_match('/^(.+)시$/u', $t, $mm)) {              // 수원시 → 수원
            $sggName = $t;
            $variants[] = $mm[1];
        } elseif (preg_match('/^(.+)군$/u', $t, $mm)) {              // 양평군 → 양평군 + 양평
            $sggName = $t;
            $variants[] = $t;
            if (mb_strlen($mm[1], 'UTF-8') >= 2) {
                $variants[] = $mm[1];
            }
        } elseif (preg_match('/^(.+)구$/u', $t, $mm)) {              // 관악구 → 관악구 + 관악
            $sggName = $t;
            $variants[] = $t;
            if (mb_strlen($mm[1], 'UTF-8') >= 2) {
                $variants[] = $mm[1];
            }
        }
    }
    foreach ($variants as $v) {
        $sgg[$v] = true;
        $put($v, $sido, $sggName);
    }

    // ── 읍면동 정규화(역삼1동 → 역삼동) ──
    if (preg_match('/[^가-힣0-9]/u', $name)) {
        continue;
    }
    $n = preg_replace('/제?\d+동$/u', '동', $name);
    if (preg_match('/\d/u', $n) || mb_strlen($n, 'UTF-8') < 2) {
        continue;
    }
    $emd[$n] = true;
    $emdOwners[$n][$sido.' '.$sggName] = true;
    $put($n, $sido, $sggName);
}

$emdList = array_keys($emd);
$sggList = array_keys($sgg);
sort($emdList);
sort($sggList);
ksort($parent);

$ambiguous = array_keys(array_filter($emdOwners, fn ($o) => count($o) > 1));
sort($ambiguous);

$q = fn (string $s) => "'".str_replace("'", "\\'", $s)."'";
$listBlock = fn (array $a) => "        ".implode(', ', array_map($q, $a)).",";
$parentBlock = implode("\n", array_map(
    fn ($k) => "        {$q($k)} => [{$q($parent[$k][0])}, {$q($parent[$k][1])}],",
    array_keys($parent),
));

$out = "<?php\n\n/**\n * 전국 행정구역 지역명 + 소속 계층(자동 생성 — scripts/generate-regions-kr.php).\n"
    ." * 원천: vuski/admdongkor 행정동 경계(행안부 기준) adm_nm 추출·검색형 정규화.\n"
    ." *   sgg/emd  : hub:place-seed 의 지역×업종 조합 풀\n"
    ." *   parent   : 지역명 => [시/도 축약, 시/군/구] — /keywords/place 3단계 드릴다운(시도>시군구>동)\n"
    ." *   ambiguous: 같은 동 이름이 여러 시군구에 있음(첫 소속으로 매핑). 표시상 오분류 가능 — 22 문서 참조\n"
    ." * ⚠️ 직접 수정하지 말 것. 상권·여행지(강남역·홍대 등) 소속은 place_keyword_matrix.php 의 region_parents 에.\n */\n"
    ."return [\n"
    ."    'sgg' => [\n".$listBlock($sggList)."\n    ],\n"
    ."    'emd' => [\n".$listBlock($emdList)."\n    ],\n"
    ."    'parent' => [\n".$parentBlock."\n    ],\n"
    ."    'ambiguous' => [\n".$listBlock($ambiguous)."\n    ],\n"
    ."];\n";

file_put_contents(__DIR__.'/../database/data/regions_kr.php', $out);
echo '생성 완료 — 시군구 '.count($sggList).' · 읍면동 '.count($emdList)
    .' · 계층 매핑 '.count($parent).' · 동명 중복 '.count($ambiguous)."개\n";
