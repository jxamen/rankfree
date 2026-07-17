<?php

/**
 * 전국 행정동 GeoJSON(vuski/admdongkor adm_nm) → database/data/regions_kr.php 생성기.
 *   php scripts/generate-regions-kr.php <HangJeongDong.geojson 경로>
 * 검색형 정규화: "역삼1동"→"역삼동"(숫자 통합), '·'· '.' 포함 명칭 제외, 1자 명칭 제외.
 * 시군구: 시→접미사 제거(수원), 군→풀네임+베이스(양평군·양평), 구→풀네임+베이스(관악구·관악).
 */
if (! isset($argv[1]) || ! is_file($argv[1])) {
    fwrite(STDERR, "사용법: php scripts/generate-regions-kr.php <geojson>\n");
    exit(1);
}

preg_match_all('/"adm_nm": *"([^"]+)"/u', (string) file_get_contents($argv[1]), $m);
$emd = [];
$sgg = [];

foreach (array_unique($m[1]) as $adm) {
    $parts = preg_split('/\s+/u', trim($adm));
    if (count($parts) < 2) {
        continue;
    }
    $name = array_pop($parts);          // 읍면동
    array_shift($parts);                 // 시도 제거
    $middle = $parts;                    // 시군구(0~2 토큰, 병합형 "수원시장안구" 포함)

    // ── 읍면동 정규화 ──
    if (! preg_match('/[^가-힣0-9]/u', $name)) {
        $n = preg_replace('/제?\d+동$/u', '동', $name);   // 역삼1동 → 역삼동
        if (! preg_match('/\d/u', $n) && mb_strlen($n, 'UTF-8') >= 2) {
            $emd[$n] = true;
        }
    }

    // ── 시군구 ──
    foreach ($middle as $t) {
        if (preg_match('/^(.+?)시(.+?구)$/u', $t, $mm)) {      // 수원시장안구 → 수원 + 장안구
            $sgg[$mm[1]] = true;
            $sgg[$mm[2]] = true;
        } elseif (preg_match('/^(.+)시$/u', $t, $mm)) {          // 수원시 → 수원
            $sgg[$mm[1]] = true;
        } elseif (preg_match('/^(.+)군$/u', $t, $mm)) {          // 양평군 → 양평군 + 양평
            $sgg[$t] = true;
            if (mb_strlen($mm[1], 'UTF-8') >= 2) {
                $sgg[$mm[1]] = true;
            }
        } elseif (preg_match('/^(.+)구$/u', $t, $mm)) {          // 관악구 → 관악구 + 관악
            $sgg[$t] = true;
            if (mb_strlen($mm[1], 'UTF-8') >= 2) {
                $sgg[$mm[1]] = true;
            }
        }
    }
}

$emd = array_keys($emd);
$sgg = array_keys($sgg);
sort($emd);
sort($sgg);

$export = fn (array $a) => "        '".implode("', '", $a)."',";
$out = "<?php\n\n/**\n * 전국 행정구역 지역명(자동 생성 — scripts/generate-regions-kr.php).\n"
    ." * 원천: vuski/admdongkor 행정동 경계(행안부 행정동 기준) adm_nm 명칭 추출·검색형 정규화.\n"
    ." * hub:place-seed 가 지역×업종 조합 시 dong(읍면동)·city(시군구) 풀에 병합한다.\n */\n"
    ."return [\n"
    ."    'sgg' => [\n".$export($sgg)."\n    ],\n"
    ."    'emd' => [\n".$export($emd)."\n    ],\n"
    ."];\n";

file_put_contents(__DIR__.'/../database/data/regions_kr.php', $out);
echo '생성 완료 — 시군구 '.count($sgg).' · 읍면동 '.count($emd)."개\n";
