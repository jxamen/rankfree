<?php

namespace Jcurve\Ga4Insights\Support;

/** 표시용 포매팅 — 숫자·비율·시간·증감. 뷰에서 호출. */
class Format
{
    public static function int(float|int|null $v): string
    {
        return number_format((int) round((float) $v));
    }

    /** 비율(0~1) → "57.1%". 이미 0~100 스케일이면 $scale=1. */
    public static function pct(float|int|null $ratio, int $decimals = 1): string
    {
        return number_format((float) $ratio * 100, $decimals).'%';
    }

    public static function pctRaw(float|int|null $v, int $decimals = 1): string
    {
        return number_format((float) $v, $decimals).'%';
    }

    /** 초 → "1분 23초" / "44초" / "1시간 2분". */
    public static function duration(float|int|null $seconds): string
    {
        $s = (int) round((float) $seconds);
        if ($s <= 0) {
            return '0초';
        }
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $sec = $s % 60;
        $parts = [];
        if ($h) {
            $parts[] = $h.'시간';
        }
        if ($m) {
            $parts[] = $m.'분';
        }
        if ($sec && ! $h) {
            $parts[] = $sec.'초';
        }

        return implode(' ', $parts) ?: '0초';
    }

    /**
     * 기간 대비 증감 → ['pct'=>float|null, 'dir'=>'up'|'down'|'flat', 'text'=>'+12.3%'|'신규'|'—'].
     * $goodUp: 값이 오르는 게 좋은 지표인지(이탈률은 false).
     */
    public static function delta(float|int|null $cur, float|int|null $prev): array
    {
        $cur = (float) $cur;
        $prev = (float) $prev;
        if ($prev <= 0) {
            return ['pct' => null, 'dir' => $cur > 0 ? 'up' : 'flat', 'text' => $cur > 0 ? '신규' : '—'];
        }
        $change = ($cur - $prev) / $prev * 100;
        $dir = abs($change) < 0.05 ? 'flat' : ($change > 0 ? 'up' : 'down');
        $sign = $change > 0 ? '+' : '';

        return ['pct' => $change, 'dir' => $dir, 'text' => $dir === 'flat' ? '±0%' : $sign.number_format($change, 1).'%'];
    }
}
