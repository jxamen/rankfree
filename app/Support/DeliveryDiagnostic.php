<?php

namespace App\Support;

final class DeliveryDiagnostic
{
    public static function sanitize(string $value): string
    {
        $input = json_decode($value, true);
        $out = ['version' => '0.6.9.0', 'paths' => [], 'screen' => []];
        foreach (array_slice((array) ($input['paths'] ?? []), 0, 50) as $pair) {
            if (! is_array($pair) || ! preg_match('/^product(?:\.[a-zA-Z_]{1,60}){1,5}$/D', $pair[0] ?? '')) {
                continue;
            }
            $v = $pair[1] ?? null;
            if (! (is_int($v) && $v >= 0 && $v <= 10000000) && ! is_bool($v)
                && ! (is_string($v) && preg_match('/^(?:[0-9,]{1,10}|FREE|PAID|CONDITIONAL_FREE|CHARGE|PREPAID|COD|CASH_ON_DELIVERY|BUNDLE|UNIT|QUANTITY|RANGE|FIXED)$/D', $v))) {
                $v = null;
            }
            $out['paths'][] = [$pair[0], $v];
        }
        foreach (array_slice((array) ($input['screen'] ?? []), 0, 8) as $line) {
            $line = preg_replace('/0\d{1,2}[- )]*\d{3,4}[- ]*\d{4}/', '', (string) $line);
            preg_match_all('/배송비|무료배송|조건부 무료|착불|묶음배송|선결제|이상|미만|무료|원|[0-9,]{1,10}/u', $line, $matches);
            $out['screen'][] = implode(' ', $matches[0]);
        }
        while (strlen(json_encode($out, JSON_UNESCAPED_UNICODE)) > 2048) {
            if ($out['paths']) {
                array_pop($out['paths']);
            } else {
                array_pop($out['screen']);
            }
        }

        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }
}
