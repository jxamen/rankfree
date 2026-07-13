<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 기존 셀러력 분석 snapshot의 rx tip에서 제거하기로 한 설명 문구를 정리한다.
 * (신규 분석은 SellerPowerScorer가 이미 짧은 문구로 생성하지만, 저장본은 옛 문구가 박혀 있음)
 */
return new class extends Migration
{
    public function up(): void
    {
        $strip = [
            ' — 네이버 미표시(리뷰로 최근판매 추정)',
            ' — 응대·배송 등 기준 충족 시 네이버가 자동 부여',
            ' — 좋아요',
            ' — 켜면 가점',
        ];

        DB::table('seller_power_analyses')->orderBy('id')->chunkById(100, function ($rows) use ($strip) {
            foreach ($rows as $row) {
                $snap = json_decode($row->snapshot ?? '', true);
                if (! is_array($snap) || empty($snap['rx'])) {
                    continue;
                }
                $changed = false;
                foreach ($snap['rx'] as &$group) {
                    if (empty($group['items']) || ! is_array($group['items'])) {
                        continue;
                    }
                    foreach ($group['items'] as &$it) { // ?? 복사본 금지 — 원본 참조로 수정
                        if (! isset($it['tip'])) {
                            continue;
                        }
                        $new = str_replace($strip, '', (string) $it['tip']);
                        if ($new !== $it['tip']) {
                            $it['tip'] = $new;
                            $changed = true;
                        }
                    }
                    unset($it);
                }
                unset($group);

                if ($changed) {
                    DB::table('seller_power_analyses')->where('id', $row->id)->update([
                        'snapshot' => json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // 문구 정리는 되돌리지 않음(원문 복원 불필요)
    }
};
