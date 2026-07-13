<?php

/**
 * 로컬 설정(메뉴·권한·횟수) → 운영 DB 임포트 (일회성).
 * 실행: php83 artisan tinker --execute='require base_path("database/import-config.php");'
 * config-export.json 의 4개 테이블을 truncate 후 그대로 삽입(계정·분석데이터는 건드리지 않음).
 */

$path = base_path('database/config-export.json');
if (! is_file($path)) {
    echo "config-export.json 없음: {$path}\n";
    return;
}

$data = json_decode(file_get_contents($path), true);
$order = ['member_grades', 'operator_roles', 'menus', 'menu_permissions'];

DB::statement('SET FOREIGN_KEY_CHECKS=0');
foreach (array_reverse($order) as $t) {
    DB::table($t)->truncate();
}
foreach ($order as $t) {
    $rows = $data[$t] ?? [];
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table($t)->insert($chunk);
    }
    echo str_pad($t, 20).': '.count($rows)." rows\n";
}
DB::statement('SET FOREIGN_KEY_CHECKS=1');
echo "임포트 완료 (계정·분석데이터 무변경)\n";
