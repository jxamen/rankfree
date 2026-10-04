<?php

namespace App\Domain\Place;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** PlacePhoneFetcher의 상호 검색 경로를 이용한다. 후보를 자동으로 확정하지 않는다. */
class PlaceCandidateFinder
{
    public function search(string $query, int $limit = 20): array
    {
        $empty = ['query' => $query, 'total' => null, 'count' => 0, 'candidates' => [], 'has_more' => false];
        try {
            $response = Http::timeout(25)->withHeaders([
                'User-Agent' => config('rankfree.place.ua'),
                'Accept-Language' => 'ko-KR,ko;q=0.9',
            ])->get('https://pcmap.place.naver.com/place/list', ['query' => $query]);
        } catch (ConnectionException) {
            return $empty + ['status' => 'unavailable'];
        }
        if (in_array($response->status(), [403, 405, 429], true)) {
            return $empty + ['status' => 'blocked'];
        }
        if (! $response->successful() || ! preg_match('/window\.__APOLLO_STATE__\s*=\s*(\{.*?\});/s', $response->body(), $m)) {
            return $empty + ['status' => 'unavailable'];
        }
        $state = json_decode($m[1], true);
        $businesses = null;
        foreach (($state['ROOT_QUERY'] ?? []) as $key => $value) {
            if (! str_starts_with((string) $key, 'placeList(')) {
                continue;
            }
            $args = json_decode(substr($key, strlen('placeList('), -1), true);
            if (($args['input']['query'] ?? null) !== $query) {
                continue;
            }
            $value = isset($value['__ref']) ? ($state[$value['__ref']] ?? []) : $value;
            $businesses = $value['businesses'] ?? null;
            if (isset($businesses['__ref'])) {
                $businesses = $state[$businesses['__ref']] ?? null;
            }
            break;
        }
        if (! is_array($businesses['items'] ?? null) || ! is_numeric($businesses['total'] ?? null)) {
            return $empty + ['status' => 'unavailable'];
        }
        $items = [];
        $incomplete = false;
        foreach ($businesses['items'] as $reference) {
            $item = isset($reference['__ref']) ? ($state[$reference['__ref']] ?? []) : $reference;
            $id = (string) ($item['id'] ?? '');
            if (! preg_match('/^\d{5,30}$/', $id) || empty($item['name'])) {
                $incomplete = true;

                continue;
            }
            if (isset($items[$id])) {
                continue;
            }
            $items[$id] = [
                'place_id' => $id, 'place_url' => PlaceRankChecker::buildMPlaceUrl($id, 'place'),
                'name' => $item['name'], 'category' => $item['category'] ?? null,
                'address' => ($item['fullAddress'] ?? '') ?: ($item['address'] ?? null),
                'road_address' => $item['roadAddress'] ?? null, 'common_address' => $item['commonAddress'] ?? null,
                'phone' => ($item['phone'] ?? '') ?: (($item['virtualPhone'] ?? '') ?: null),
                'latitude' => is_numeric($item['y'] ?? null) ? (float) $item['y'] : null,
                'longitude' => is_numeric($item['x'] ?? null) ? (float) $item['x'] : null,
            ];
        }
        $total = (int) $businesses['total'];
        if ($total > 0 && ! $items) {
            return $empty + ['status' => 'unavailable'];
        }
        $items = array_slice(array_values($items), 0, $limit);

        return ['query' => $query, 'status' => $incomplete ? 'partial' : 'ok', 'total' => $total,
            'count' => count($items), 'has_more' => $total > count($items), 'candidates' => $items];
    }
}
