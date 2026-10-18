<?php

namespace App\Domain\Shopping;

use Carbon\CarbonImmutable;

/** No network resolution: only an explicit product identity AND core keyword match. */
class ShopKeywordAdEndPolicy
{
    public const ACTIVE_STATUSES = ['pending', 'checking', 'processing', 'running', 'paused', 'blocked'];

    public function productKey(?string $url): ?string
    {
        $url = html_entity_decode(trim((string) $url));
        $parts = parse_url($url);
        if (! $parts || empty($parts['host'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        $path = rawurldecode($parts['path'] ?? '');
        if (in_array($host, ['smartstore.naver.com', 'm.smartstore.naver.com', 'brand.naver.com'], true)
            && preg_match('~/products/(\d+)~', $path, $m)) {
            return 'channel:'.$m[1];
        }
        if (in_array($host, ['shopping.naver.com', 'm.shopping.naver.com', 'search.shopping.naver.com'], true)
            && preg_match('~/(?:catalog|products)/(\d+)~', $path, $m)) {
            return 'catalog:'.$m[1];
        }

        return $host.rtrim($path, '/').(! empty($parts['query']) ? '?'.$parts['query'] : '');
    }

    public function keywordKey(string $keyword): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', trim($keyword)));
    }

    public function index(array $campaigns): array
    {
        $index = [];
        foreach ($campaigns as $c) {
            foreach (['link', 'link2', 'link3', 'catalog_product_url'] as $field) {
                $key = $this->productKey($c[$field] ?? null);
                $keyword = $this->keywordKey($c['keyword'] ?? '');
                if ($key && $keyword !== '') {
                    $index[$key.'|'.$keyword][(int) $c['no']] = $c;
                }
            }
        }

        return $index;
    }

    public function decide(array $analysis, array $index, CarbonImmutable $now): array
    {
        $today = $now->setTimezone('Asia/Seoul')->toDateString();
        $end = $analysis['order_end_date'] ?? null;
        $key = $this->productKey($analysis['product_url'] ?? null);
        $matches = array_values($index[($key ?? '').'|'.$this->keywordKey($analysis['core_keyword'])] ?? []);
        $result = ['id' => (int) $analysis['id'], 'previous_status' => $analysis['status'], 'reason' => null,
            'campaign_ids' => array_column($matches, 'no'), 'last_collected_at' => $analysis['last_collected_at'] ?? null];
        if (! in_array($analysis['status'], self::ACTIVE_STATUSES, true)) {
            return $result;
        }
        if ($end && $end < $today) {
            $result['reason'] = 'order_expired';
        } elseif (! $end && $matches !== []) {
            // Any live, future, waiting or unknown matching campaign protects a renewed order.
            $allEnded = collect($matches)->every(fn ($c) => in_array($c['status'], ['end', 'cancel'], true)
                || (! empty($c['to_date']) && $c['to_date'] !== '0000-00-00' && $c['to_date'] < $today));
            if ($allEnded) {
                $result['reason'] = 'campaign_ended';
            }
        } elseif ($matches === []) {
            $last = $analysis['last_collected_at'] ?? $analysis['created_at'] ?? null;
            if ($last && CarbonImmutable::parse($last, config('app.timezone', 'UTC'))->lte($now->subDays(7))) {
                $result['reason'] = 'unmatched_stale';
            }
        }

        return $result;
    }
}
