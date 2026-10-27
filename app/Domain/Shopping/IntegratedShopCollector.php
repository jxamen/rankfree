<?php

namespace App\Domain\Shopping;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class IntegratedShopCollector
{
    public function __construct(private IntegratedShopParser $parser) {}

    public function collect(string $keyword, string $device): array
    {
        // File store is shared by all PHP workers on this single production server.
        $cache = Cache::store('file');
        $key = 'integrated-shop:v2:'.hash('sha256', $device."\0".$keyword);
        if ($value = $cache->get($key)) {
            return $value + ['cached' => true];
        }
        if ($cache->has('integrated-shop:blocked')) {
            return $this->error('blocked');
        }
        $lock = $cache->lock('integrated-shop:collector', 30);
        if (! $lock->get()) {
            return $this->error('busy');
        }
        try {
            if ($value = $cache->get($key)) {
                return $value + ['cached' => true];
            }
            if ($cache->has('integrated-shop:blocked')) {
                return $this->error('blocked');
            }
            $wait = max(0, (float) $cache->get('integrated-shop:next-at', 0) - microtime(true));
            if ($wait > 0) {
                usleep((int) (min($wait, 1) * 1000000));
            }
            $url = $device === 'pc' ? 'https://search.naver.com/search.naver' : 'https://m.search.naver.com/search.naver';
            try {
                $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0', 'Accept-Language' => 'ko-KR,ko;q=0.9'])
                    ->connectTimeout(3)->timeout(5)->withOptions(['allow_redirects' => false])
                    ->get($url, $device === 'pc' ? ['where' => 'nexearch', 'query' => $keyword] : ['query' => $keyword]);
            } catch (ConnectionException $e) {
                return $this->error('fetch_failed');
            } finally {
                $cache->put('integrated-shop:next-at', microtime(true) + 1, 60);
            }
            $html = $response->body();
            // Ignore scripts/styles: ordinary search HTML can reference captcha assets.
            $visible = html_entity_decode(strip_tags(preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (in_array($response->status(), [403, 429], true) || preg_match('/보안\s*확인|자동입력\s*방지|비정상적인\s*접근|접근이\s*제한|security\s*verification/i', $visible) || preg_match('/captcha|security/i', (string) $response->header('Location'))) {
                $cache->put('integrated-shop:blocked', true, 600);

                return $this->error('blocked');
            }
            if (! $response->successful()) {
                return $this->error('fetch_failed');
            }
            $value = $this->parser->parse($html) + ['fetched_at' => now()->toIso8601String()];
            if (in_array($value['status'], ['ok', 'area_absent'], true)) {
                $cache->put($key, $value, 600);
            }

            return $value + ['cached' => false];
        } finally {
            $lock->release();
        }
    }

    private function error(string $status): array
    {
        return ['status' => $status, 'area_code' => null, 'items' => [], 'cached' => false, 'fetched_at' => null];
    }
}
