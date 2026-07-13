<?php

namespace App\Console\Commands;

use App\Domain\Place\PlaceCalibration;
use App\Domain\Place\PlaceWeightLearner;
use App\Models\PlaceSeoScore;
use App\Models\SmartplaceAccount;
use Illuminate\Console\Command;

/**
 * N2 가중치 학습 진단 — 소유매장의 실제 조회수(PV)를 라벨로 세부지표 가중치를 재추정.
 *
 * 라벨(소유매장) = 스마트플레이스 조회수(place_seo 와 place_id 로 연결)가 있는 매장.
 * 표본이 apply_min_stores 이상일 때만 학습 반영을 권고하고, 그 전엔 진단만 출력한다.
 *
 * 사용: php artisan place:learn-weights
 */
class LearnN2Weights extends Command
{
    protected $signature = 'place:learn-weights {--min= : 학습 반영 최소 라벨 매장 수(기본 config)}';

    protected $description = '소유매장 실측 조회수로 N2 가중치를 학습·진단(프라이어 수축)';

    public function handle(): int
    {
        $accounts = SmartplaceAccount::whereNotNull('place_id')->where('place_id', '!=', '')->get();

        $samples = [];
        $stores = 0;
        foreach ($accounts as $acct) {
            $pid = preg_replace('/\D/', '', (string) $acct->place_id);
            if ($pid === '') {
                continue;
            }
            $pv = PlaceCalibration::dailyPv(is_array($acct->last_result) ? $acct->last_result : null);
            if (! $pv) {
                continue;
            }
            $scores = PlaceSeoScore::where('place_id', $pid)->where('is_mine', true)
                ->orderBy('ymd')->get(['ymd', 'd1', 'd2', 'd3', 'd4', 'd5', 'd6', 'd7', 'd9', 'd10']);
            $added = 0;
            foreach ($scores as $sc) {
                $ymd = $sc->ymd instanceof \Illuminate\Support\Carbon ? $sc->ymd->toDateString() : substr((string) $sc->ymd, 0, 10);
                if (! isset($pv[$ymd])) {
                    continue;
                }
                $samples[] = [
                    'store' => $pid,
                    'pv' => (float) $pv[$ymd],
                    'd' => [
                        'd1' => self::f($sc->d1), 'd2' => self::f($sc->d2), 'd3' => self::f($sc->d3),
                        'd4' => self::f($sc->d4), 'd5' => self::f($sc->d5), 'd6' => self::f($sc->d6),
                        'd7' => self::f($sc->d7), 'd9' => self::f($sc->d9), 'd10' => self::f($sc->d10),
                    ],
                ];
                $added++;
            }
            if ($added > 0) {
                $stores++;
                $this->line("· {$pid} ({$acct->label}) — 표본 {$added}일");
            }
        }

        if (! $samples) {
            $this->warn('라벨 표본이 없습니다. 필요: 소유매장이 (1) 스마트플레이스 수집(조회수) + (2) 경쟁분석(내 매장 점수) 둘 다 있고 날짜가 겹쳐야 합니다.');

            return self::SUCCESS;
        }

        $min = $this->option('min') !== null ? (int) $this->option('min') : null;
        $r = PlaceWeightLearner::recommend($samples, null, $min);

        $this->newLine();
        $this->info("라벨 매장 {$r['n_stores']}개 · 표본 {$r['n_samples']}일 · trust={$r['trust']} · 반영기준 {$r['apply_min']}개");
        $this->line($r['apply'] ? "<info>{$r['verdict']}</info>" : "<comment>{$r['verdict']}</comment>");

        $this->newLine();
        $this->line('<options=bold>차원별 근거(조회수와의 상관) 및 가중치</>');
        $rows = [];
        foreach ($r['prior'] as $d => $pw) {
            $ev = $r['evidence'][$d] ?? ['corr' => null, 'coverage' => 0];
            $corr = $ev['corr'] === null ? '—' : number_format($ev['corr'], 2);
            $rec = number_format($r['recommended'][$d], 3);
            $delta = $r['recommended'][$d] - $pw;
            $arrow = abs($delta) < 0.0005 ? '' : ($delta > 0 ? '▲' : '▼');
            $rows[] = [$d, number_format($pw, 3), $rec.' '.$arrow, $corr, $ev['coverage']];
        }
        $this->table(['차원', '프라이어', '권고(정규화)', 'PV상관', '표본'], $rows);

        if (! $r['apply']) {
            $this->line('<comment>→ 현재는 프라이어를 유지합니다. 라벨 매장이 늘면 자동으로 학습 가중치가 권고됩니다.</comment>');
            $this->line('  적용하려면 config/rankfree.php 의 scoring.n2_weights 를 권고값으로 갱신하세요(수동 검토 후).');
        }

        return self::SUCCESS;
    }

    private static function f(mixed $v): ?float
    {
        return ($v === null || $v === '') ? null : (float) $v;
    }
}
