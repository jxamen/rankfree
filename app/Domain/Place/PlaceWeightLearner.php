<?php

namespace App\Domain\Place;

/**
 * N2 가중치 학습기 — 소유매장의 실제 조회수(PV)를 라벨로 세부지표(D1~D10) 가중치를 개선.
 *
 * 핵심 제약: 라벨(소유매장)이 적으면 8개 가중치를 자유롭게 학습 → 과적합.
 * 그래서 프라이어(현 수동 가중치)에서 출발해 데이터가 말하는 만큼만 움직인다(shrink-to-prior):
 *   w_new[d] = prior[d] · exp(β · trust · clamp(corr(D,PV)))     trust = n_store / (n_store + K)
 * - 표본(소유매장)이 적으면 trust→0 → w_new ≈ prior (거의 안 움직임).
 * - 표본 3개 미만이면 어떤 차원도 상관을 못 구해 recommended == prior (정확히 동일).
 * - apply(실반영)는 n_store ≥ apply_min 이고 근거 차원 ≥3 일 때만 true. 그 전엔 진단만.
 * 순수 계산(네트워크·DB 없음) → 유닛 테스트로 검증.
 */
class PlaceWeightLearner
{
    /** N2 에 쓰는 차원(키워드 일치 D8=N1 은 제외). */
    private const DIMS = ['d1', 'd2', 'd3', 'd4', 'd5', 'd6', 'd7', 'd9', 'd10'];
    private const K = 8.0;              // trust 반감 상수(라벨 K개에서 trust=0.5)
    private const BETA = 0.7;           // 최대 로그-조정 폭
    private const MIN_COVERAGE = 3;     // 차원별 최소 표본(미만이면 그 차원은 프라이어 유지)

    /**
     * @param  array<int, array{store?:string, d:array<string,?float>, pv:float}>  $samples
     * @param  array<string,float>|null  $prior     null 이면 현재 config 가중치
     * @param  int|null  $applyMin  학습 반영 최소 라벨 매장 수(null 이면 config)
     */
    public static function recommend(array $samples, ?array $prior = null, ?int $applyMin = null): array
    {
        $prior = self::normalize($prior ?? PlaceScorer::n2Weights());
        $applyMin ??= (int) (function_exists('config') ? config('rankfree.scoring.apply_min_stores', 10) : 10);

        $storeSet = [];
        foreach ($samples as $s) {
            if (! empty($s['store'])) {
                $storeSet[(string) $s['store']] = true;
            }
        }
        $nStores = count($storeSet);
        $nSamples = count($samples);
        $unit = $nStores ?: $nSamples;                 // 독립 표본 근사 = 매장 수(없으면 표본 수)
        $trust = $unit > 0 ? $unit / ($unit + self::K) : 0.0;

        $evidence = [];
        $recommended = [];
        foreach (self::DIMS as $d) {
            $xs = [];
            $ys = [];
            foreach ($samples as $s) {
                $v = $s['d'][$d] ?? null;
                if ($v === null) {
                    continue;
                }
                $xs[] = (float) $v;
                $ys[] = (float) $s['pv'];
            }
            $corr = count($xs) >= self::MIN_COVERAGE ? PlaceCalibration::pearson($xs, $ys) : null;
            $evidence[$d] = ['corr' => $corr, 'coverage' => count($xs)];
            $recommended[$d] = $corr === null
                ? $prior[$d]                            // 근거 부족 → 프라이어 유지
                : $prior[$d] * exp(self::BETA * $trust * max(-1.0, min(1.0, $corr)));
        }
        $recommended = self::normalize($recommended);

        $withCorr = count(array_filter($evidence, fn ($e) => $e['corr'] !== null));
        $apply = $nStores >= $applyMin && $withCorr >= 3;

        return [
            'n_stores' => $nStores,
            'n_samples' => $nSamples,
            'trust' => round($trust, 3),
            'apply_min' => $applyMin,
            'apply' => $apply,
            'prior' => $prior,
            'recommended' => $recommended,
            'evidence' => $evidence,
            'verdict' => $apply
                ? "학습 가중치 반영 가능 (라벨 매장 {$nStores}개, 근거 차원 {$withCorr}개)."
                : "표본 부족 (라벨 매장 {$nStores}/{$applyMin}) — 프라이어 유지, 진단만 제공.",
        ];
    }

    /** 합=1 정규화(음수·전무 방어). */
    private static function normalize(array $w): array
    {
        $sum = 0.0;
        foreach ($w as $v) {
            $sum += max(0.0, (float) $v);
        }
        if ($sum <= 0) {
            return $w;
        }
        $out = [];
        foreach ($w as $k => $v) {
            $out[$k] = round(max(0.0, (float) $v) / $sum, 4);
        }

        return $out;
    }
}
