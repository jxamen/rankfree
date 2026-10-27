<?php

namespace App\Domain\Shopping;

use JsonException;
use UnexpectedValueException;

/** Parses only JSON literals; never executes the surrounding JavaScript. */
class IntegratedShopParser
{
    public function parse(string $html): array
    {
        $pattern = '/naver\s*\.\s*search\s*\.\s*ext\s*\.\s*newshopping\s*(?:\.\s*shopping|\[\s*["\']shopping["\']\s*\])\s*\.\s*_INITIAL_STATE\s*=\s*/';
        if (! preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE)) {
            return $this->result(! preg_match('/<html\b/i', $html) || ! str_contains(strtolower($html), '</html>') || str_contains($html, 'newshopping') || str_contains($html, 'shp_gui_root') || str_contains($html, 'shp_tli_root') ? 'parse_failed' : 'area_absent');
        }
        try {
            $start = $match[0][1] + strlen($match[0][0]);
            $state = $this->decodeObject($html, $start);
            if (! is_string($state['areaCode'] ?? null) || ! preg_match('/^shp_[a-z0-9_]+$/D', $state['areaCode'])) {
                throw new UnexpectedValueException;
            }
            $pages = $state['initProps']['pagedSlot'] ?? null;
            if (! is_array($pages) || ! array_is_list($pages) || count($pages) < 1 || ($pages[0]['page'] ?? null) !== 1 || ! is_array($pages[0]['slots'] ?? null) || ! array_is_list($pages[0]['slots'])) {
                throw new UnexpectedValueException;
            }
            $items = [];
            $organic = 0;
            foreach ($pages[0]['slots'] as $slot) {
                $data = $slot['data'] ?? null;
                $cards = match ($slot['slotType'] ?? null) {
                    'CARD' => [$data],
                    'HORIZONTAL_LIST' => is_array($data) && array_is_list($data) ? $data : throw new UnexpectedValueException,
                    default => throw new UnexpectedValueException,
                };
                foreach ($cards as $card) {
                    if (! is_array($card) || ! isset($card['nvMid'], $card['sourceType'], $card['cardType']) || ! is_scalar($card['nvMid']) || ! is_string($card['sourceType']) || ! is_string($card['cardType'])) {
                        throw new UnexpectedValueException;
                    }
                    $ad = in_array($card['sourceType'], ['AD', 'AITEMS_FORYOU_AD'], true) || $card['cardType'] === 'AD_CARD';
                    $item = [];
                    foreach (['nvMid', 'channelProductId', 'channelNo', 'mallId', 'mallName', 'sourceType', 'cardType', 'salePrice'] as $field) {
                        $value = $card[$field] ?? null;
                        if ($value !== null && ! is_scalar($value)) {
                            throw new UnexpectedValueException;
                        }
                        $item[$field] = $value === null ? null : ($field === 'salePrice' ? $value : (string) $value);
                    }
                    if (! is_string($card['productName'] ?? null)) {
                        throw new UnexpectedValueException;
                    }
                    $urls = $card['productUrl'] ?? null;
                    if ($urls !== null && ! is_array($urls)) {
                        throw new UnexpectedValueException;
                    }
                    $item['productUrl'] = null;
                    if ($urls !== null) {
                        $item['productUrl'] = [];
                        foreach (['pcUrl', 'mobileUrl'] as $urlKey) {
                            if (isset($urls[$urlKey]) && ! is_string($urls[$urlKey])) {
                                throw new UnexpectedValueException;
                            }
                            $item['productUrl'][$urlKey] = $urls[$urlKey] ?? null;
                        }
                    }
                    $item['productName'] = html_entity_decode(strip_tags($card['productName']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $item['rank'] = count($items) + 1;
                    $item['organic_rank'] = $ad ? null : ++$organic;
                    $item['is_ad'] = $ad;
                    $items[] = $item;
                }
            }

            return ['status' => 'ok', 'area_code' => $state['areaCode'], 'items' => $items];
        } catch (JsonException|UnexpectedValueException $e) {
            return $this->result('parse_failed');
        }
    }

    private function result(string $status): array
    {
        return ['status' => $status, 'area_code' => null, 'items' => []];
    }

    private function decodeObject(string $text, int $start): array
    {
        if (($text[$start] ?? '') !== '{') {
            throw new UnexpectedValueException;
        }
        $depth = 0;
        $quoted = false;
        $escaped = false;
        for ($i = $start, $length = strlen($text); $i < $length; $i++) {
            $char = $text[$i];
            if ($quoted) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $quoted = false;
                }

                continue;
            }
            if ($char === '"') {
                $quoted = true;
            } elseif ($char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === '}' || $char === ']') {
                if (--$depth === 0) {
                    return json_decode(substr($text, $start, $i - $start + 1), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                }
            }
        }
        throw new UnexpectedValueException;
    }
}
