<?php

namespace App\Domain\Place;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** 순위/SEO 수집과 같은 m.place Apollo 데이터를 외부 조회용으로 보존한다. */
class PlaceInfoFetcher
{
    public function fetch(string $placeId, string $category = 'place', int $reviewLimit = 10): array
    {
        $category = in_array($category, PlaceRankChecker::PLACE_CATEGORIES, true) ? $category : 'place';
        $baseUrl = "https://m.place.naver.com/{$category}/{$placeId}";
        $states = [];
        $status = [];
        foreach (['business' => 'home', 'menus' => 'menu', 'recent_reviews' => 'review/visitor?reviewSort=recent'] as $section => $path) {
            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36',
                    'Accept' => 'text/html', 'Accept-Language' => 'ko-KR,ko;q=0.9',
                ])->timeout((int) config('rankfree.place.timeout', 20))->get("{$baseUrl}/{$path}");
                $state = $response->successful() ? $this->state($response->body()) : null;
                $states[$section] = $state ?? [];
                $status[$section] = in_array($response->status(), [403, 405, 429], true)
                    ? 'blocked' : ($state !== null ? 'ok' : 'unavailable');
            } catch (ConnectionException $e) {
                $states[$section] = [];
                $status[$section] = 'unavailable';
            }
        }

        $state = $states['business'];
        // 다른 업체 추천 노드를 대상 업체 정보로 오인하지 않는다.
        $base = $state['PlaceDetailBase:'.$placeId] ?? null;
        if (! is_array($base)) {
            foreach ($state as $key => $value) {
                if (str_ends_with($key, 'DetailBase:'.$placeId) && is_array($value)) {
                    $base = $value;
                    break;
                }
            }
        }
        if (! is_array($base) || empty($base['name'])) {
            $status['business'] = $status['business'] === 'blocked' ? 'blocked' : 'unavailable';
            $base = [];
        }
        $base = $this->expand($base, $state);
        $detail = $this->rootField($state, 'placeDetail', $placeId);
        $detail = $this->expand($detail, $state);
        $menus = [];
        // /menu 가 없는 업종은 /home 에 포함된 메뉴도 활용한다.
        $menuState = array_replace($state, $states['menus']);
        foreach ($menuState as $key => $value) {
            if ((str_starts_with($key, 'Menu:') || str_starts_with($key, 'PlaceMenuItem:')) && is_array($value)) {
                $value = $this->expand($value, $menuState);
                $price = $value['price'] ?? null;
                $menus[] = [
                    'id' => (string) ($value['id'] ?? explode(':', $key, 2)[1]),
                    'name' => $value['name'] ?? null,
                    'price' => is_array($price) ? ($price['displayText'] ?? null) : (isset($price) ? (string) $price : null),
                    'description' => $value['description'] ?? null,
                    'images' => $value['images'] ?? [],
                ];
            }
        }

        $reviews = [];
        $reviewState = $states['recent_reviews'];
        // ROOT_QUERY 의 최신순·본문 포함 목록 순서를 사용한다(작성일 원문은 "10.4.일" 등).
        // 캐시에는 같은 리뷰의 본문 없는 버전/추천 리뷰도 함께 있으므로 전체 노드를 섞지 않는다.
        $reviewList = $this->rootField($reviewState, 'visitorReviews', $placeId, true);
        $ordered = array_key_exists('items', $reviewList);
        $candidates = $ordered ? $reviewList['items'] : array_filter($reviewState,
            fn ($key) => str_starts_with($key, 'VisitorReview:'), ARRAY_FILTER_USE_KEY);
        $seen = [];
        foreach ($candidates as $value) {
            if (! is_array($value)) {
                continue;
            }
            $value = $this->expand($value, $reviewState);
            $id = (string) ($value['reviewId'] ?? $value['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $reviews[] = [
                'id' => $id,
                'body' => $value['body'] ?? null,
                'created_at' => $value['created'] ?? null,
                'visited_at' => $value['visited'] ?? null,
                'rating' => isset($value['rating']) ? (float) $value['rating'] : null,
                'author' => $value['author']['nickname'] ?? null,
                'media' => array_values(array_map(fn ($media) => array_intersect_key($media, array_flip(['type', 'url', 'thumbnail'])),
                    array_filter($value['media'] ?? [], 'is_array'))),
            ];
        }
        if (! $ordered) {
            usort($reviews, fn ($a, $b) => $this->reviewDate($b['created_at']) <=> $this->reviewDate($a['created_at']));
        }
        $coordinate = $base['coordinate'] ?? [];

        return [
            'place' => [
                'place_id' => $placeId, 'place_url' => $baseUrl.'/home',
                'name' => $base['name'] ?? null, 'category' => $base['category'] ?? null,
                'phone' => ($base['virtualPhone'] ?? '') ?: ($base['phone'] ?? null),
                'description' => $detail['description'] ?? $base['description'] ?? null,
                'business_hours' => $detail['newBusinessHours'] ?? $detail['businessHours'] ?? $base['openingHours'] ?? $base['businessHours'] ?? null,
                'conveniences' => $base['conveniences'] ?? [],
                'payment_info' => $base['paymentInfo'] ?? [],
                'location' => [
                    'address' => $base['address'] ?? null, 'road_address' => $base['roadAddress'] ?? null,
                    'directions' => $base['road'] ?? null,
                    'latitude' => $this->coordinate($coordinate['y'] ?? $base['y'] ?? null, 90),
                    'longitude' => $this->coordinate($coordinate['x'] ?? $base['x'] ?? null, 180),
                ],
                'review_count' => isset($base['visitorReviewsTotal']) ? (int) $base['visitorReviewsTotal'] : null,
                'blog_review_count' => isset($base['cafeBlogReviewsTotal']) ? (int) $base['cafeBlogReviewsTotal'] : null,
                'menus' => $menus,
                'recent_reviews' => array_slice($reviews, 0, $reviewLimit),
            ],
            'status' => $status,
            'partial' => in_array('unavailable', $status, true) || in_array('blocked', $status, true),
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    private function rootField(array $state, string $field, string $placeId, bool $recent = false): array
    {
        foreach ($state['ROOT_QUERY'] ?? [] as $key => $value) {
            if (! str_starts_with($key, $field.'(') || ! is_array($value)) {
                continue;
            }
            $args = json_decode(substr($key, strlen($field) + 1, -1), true);
            $input = $args['input'] ?? [];
            if ((string) ($input['businessId'] ?? $input['id'] ?? '') !== $placeId) {
                continue;
            }
            if ($recent && (($input['sort'] ?? '') !== 'recent' || ($input['includeContent'] ?? true) !== true)) {
                continue;
            }

            return $value;
        }

        return [];
    }

    private function reviewDate(?string $value): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value ?? '', $m)) {
            return $m[0];
        }
        if (preg_match('/^(?:(\d{2,4})\.)?(\d{1,2})\.(\d{1,2})\./', $value ?? '', $m)) {
            $year = $m[1] !== '' ? (int) $m[1] : (int) now()->format('Y');
            if ($year < 100) {
                $year += 2000;
            }
            $date = sprintf('%04d-%02d-%02d', $year, $m[2], $m[3]);
            if ($m[1] === '' && $date > now()->toDateString()) {
                $date = sprintf('%04d-%02d-%02d', $year - 1, $m[2], $m[3]);
            }

            return $date;
        }

        return '';
    }

    private function state(string $html): ?array
    {
        if (! preg_match('/window\.__APOLLO_STATE__\s*=\s*(\{.*?\});/s', $html, $match)) {
            return null;
        }
        $state = json_decode($match[1], true);

        return is_array($state) && $state !== [] ? $state : null;
    }

    /** Apollo 참조 해제. 순환 참조·내부 메타데이터는 응답에서 제외한다. */
    private function expand(array $value, array $state, int $depth = 0): array
    {
        if ($depth >= 8) {
            return [];
        }
        if (isset($value['__ref'])) {
            return $this->expand($state[$value['__ref']] ?? [], $state, $depth + 1);
        }
        $out = [];
        foreach ($value as $key => $item) {
            if ($key === '__typename') {
                continue;
            }
            $out[$key] = is_array($item) ? $this->expand($item, $state, $depth + 1) : $item;
        }

        return $out;
    }

    private function coordinate(mixed $value, int $limit): ?float
    {
        return is_numeric($value) && abs((float) $value) <= $limit ? (float) $value : null;
    }
}
