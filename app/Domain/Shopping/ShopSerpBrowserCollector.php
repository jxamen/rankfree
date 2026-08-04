<?php

namespace App\Domain\Shopping;

use App\Models\ShopRankSlot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * 쇼핑 검색 결과를 **서버 브라우저**로 수집한다(2026-08-04).
 *
 * 왜 브라우저인가 — 실측:
 *   · openapi shop.json 은 2026-07-31 종료(공지 32564)
 *   · ns-portal slot API 는 curl 로 되지만 **20위까지**(page 파라미터 무시)
 *   · 그 이상은 search.shopping.naver.com/ns/v1/search/paged-composite-cards 인데
 *     이 호스트는 순수 curl 을 **토큰·쿠키가 있어도 전부 418** 로 막는다(TLS 지문 차단)
 *   → 실제 브라우저만 가능. headful + persistent 프로필 + 로그인 세션 + 기기등록 통과가 모두 필요하다.
 *
 * 서버 부담 통제:
 *   · 브라우저는 **동시 1개**만(Cache 락) — 공용 서버라 병렬로 띄우지 않는다
 *   · 같은 키워드는 짧게 **캐시**해 여러 슬롯이 재사용한다(1콜로 N슬롯 판정)
 */
class ShopSerpBrowserCollector
{
    public function __construct(
        private NaverShoppingRankService $engine,
        private NaverShopExposureService $exposure,
    ) {}

    /**
     * 순위수집용 로그인 세션 확보. 세션이 살아 있으면 스크립트가 알아서 생략한다.
     * $manual = 창을 띄워 사람이 직접 로그인(캡차가 걸렸을 때 1회 통과용, 로컬에서 실행).
     */
    public function login(bool $checkOnly = false, bool $manual = false): array
    {
        $cfg = $this->cfg();
        $args = [base_path('scripts/naver-shop-login.cjs')];
        if ($checkOnly) {
            $args[] = '--check-only';
        }
        if ($manual) {
            $args[] = '--manual';
        }

        return $this->runNode($args, [
            'NAVER_SHOP_LOGIN_ID' => (string) ($cfg['login']['id'] ?? ''),
            'NAVER_SHOP_LOGIN_PW' => (string) ($cfg['login']['pw'] ?? ''),
        ], $manual ? 420 : 300) ?? ['ok' => false, 'reason' => 'no_output'];
    }

    /**
     * 키워드 검색결과 수집(광고 포함, 수집 순서 유지). 같은 키워드는 캐시를 재사용한다.
     *
     * @return array{ok:bool, items:list<array>, blocked?:bool, error?:string}
     */
    public function collect(string $keyword, ?int $pages = null): array
    {
        $cfg = $this->cfg();
        $kw = trim($keyword);
        $pages = $pages ?: (int) ($cfg['pages'] ?? 5);
        if ($kw === '') {
            return ['ok' => false, 'items' => [], 'error' => 'empty_keyword'];
        }

        $key = 'shop-serp:'.md5($kw).':'.$pages;
        $ttl = (int) ($cfg['cache_ttl'] ?? 600);
        if ($ttl > 0 && ($hit = Cache::get($key)) && is_array($hit)) {
            return $hit;   // 순수 배열만 캐시한다(객체 금지 — 운영 database 캐시가 깨진다)
        }

        // 브라우저는 동시에 1개만. 다른 수집이 도는 중이면 그것이 끝날 때까지 기다린다.
        $lock = Cache::lock('shop-serp:browser', (int) ($cfg['timeout'] ?? 180) + 30);
        try {
            if (! $lock->block((int) ($cfg['lock_wait'] ?? 120))) {
                return ['ok' => false, 'items' => [], 'blocked' => true, 'error' => 'busy'];
            }
        } catch (Throwable) {
            return ['ok' => false, 'items' => [], 'blocked' => true, 'error' => 'lock_timeout'];
        }

        try {
            // 락을 잡은 뒤 한 번 더 — 대기 중 다른 프로세스가 같은 키워드를 채웠을 수 있다.
            if ($ttl > 0 && ($hit = Cache::get($key)) && is_array($hit)) {
                return $hit;
            }

            // 결과는 **파일**로 받는다 — 수집 JSON 이 수십 KB 라 stdout 으로 받으면 유실된다(실측).
            $out = storage_path('app/shop-serp/'.md5($kw.'|'.$pages).'.json');
            @unlink($out);
            $this->runNode([
                base_path('scripts/naver-shop-serp.cjs'),
                '--query', $kw,
                '--pages', (string) $pages,
                '--out-file', $out,
            ], [], (int) ($cfg['timeout'] ?? 180) + 20);

            $json = is_file($out) ? json_decode((string) file_get_contents($out), true) : null;
            @unlink($out);
            if (! is_array($json)) {
                return ['ok' => false, 'items' => [], 'error' => 'no_output'];
            }
            if (empty($json['ok'])) {
                // blocked=true 면 세션 문제 — 순위를 0(미노출)으로 기록하면 안 된다.
                return ['ok' => false, 'items' => [], 'blocked' => ! empty($json['blocked']), 'error' => 'collect_failed'];
            }

            $out = ['ok' => true, 'items' => array_values((array) ($json['items'] ?? [])), 'total' => $json['total'] ?? null];
            if ($ttl > 0) {
                Cache::put($key, $out, $ttl);
            }

            return $out;
        } finally {
            $lock->release();
        }
    }

    /**
     * 상위 20위만 **서버 curl 1콜**(약 0.3초)로 확인한다.
     * 찾으면 checkRank 와 같은 형태로 돌려주고, 20위 안에 없거나 차단이면 null 을 준다
     * (null = "더 깊이 봐야 한다" — 확장 워커나 브라우저 수집으로 넘긴다. 미노출로 단정하지 않는다).
     */
    public function quickCheck(ShopRankSlot $slot): ?array
    {
        $target = $this->engine->resolveTarget((string) $slot->product_url);
        $r = $this->exposure->exposureBySlotApi((string) $slot->keyword, [
            'id_kind' => (string) ($target['id_kind'] ?? 'channel'),
            'product_id' => (string) ($slot->product_id ?: ($target['product_id'] ?? '')),
            'mall_name' => (string) $slot->mall_name,
        ]);
        if (empty($r['found'])) {
            return null;
        }

        $me = (array) ($r['me'] ?? []);

        return [
            'blocked' => false,
            'found' => true,
            'rank' => (int) $r['rank'],
            'total' => (int) ($r['total'] ?? 0),
            'product_id' => (string) $slot->product_id,
            'title' => (string) ($me['title'] ?? ''),
            'mall_name' => (string) ($me['mall'] ?? $slot->mall_name),
            'price' => (int) ($me['price'] ?? 0),
            'link' => '',
            'image' => '',
        ];
    }

    /**
     * 슬롯 1개의 순위 판정 — NaverShoppingRankService::checkRank 와 같은 형태로 돌려준다.
     *
     * @return array{blocked:bool, found:bool, rank:int, total:int, product_id:string, title:string, mall_name:string, price:int, link:string, image:string, error?:string}
     */
    public function checkRank(ShopRankSlot $slot): array
    {
        $res = [
            'blocked' => false, 'found' => false, 'rank' => 0, 'total' => 0,
            'product_id' => (string) $slot->product_id,
            'title' => '', 'mall_name' => (string) $slot->mall_name, 'price' => 0, 'link' => '', 'image' => '',
        ];

        $target = $this->engine->resolveTarget((string) $slot->product_url);
        $idKind = (string) ($target['id_kind'] ?? 'channel');
        $pid = (string) ($slot->product_id ?: ($target['product_id'] ?? ''));
        $mall = $this->norm((string) $slot->mall_name);

        // 1) 빠른 경로 — 상위 20위는 slot API 1콜로 끝낸다(브라우저를 띄우지 않는다)
        if (($quick = $this->quickCheck($slot)) !== null) {
            return $quick;
        }

        // 2) 20위 안에 없다 — 브라우저로 깊은 순위를 수집한다.
        //    (slot API 가 차단이었어도 여기서 다시 확인하므로 미노출로 단정하지 않는다)
        $col = $this->collect((string) $slot->keyword);
        if (empty($col['ok'])) {
            $res['blocked'] = ! empty($col['blocked']);
            $res['error'] = (string) ($col['error'] ?? 'collect_failed');

            return $res;
        }

        $organic = 0;
        foreach ($col['items'] as $it) {
            if (! empty($it['isAd'])) {
                continue;              // 광고 슬롯은 오가닉 순위에서 제외
            }
            $organic++;
            if ($res['found'] || ! $this->matches($it, $idKind, $pid, $mall)) {
                continue;
            }
            $res['found'] = true;
            $res['rank'] = $organic;
            $res['title'] = (string) ($it['productName'] ?? '');
            $res['mall_name'] = (string) ($it['mallName'] ?? $res['mall_name']);
            $res['price'] = (int) ($it['discountedSalePrice'] ?? $it['salePrice'] ?? 0);
            $res['link'] = (string) ($it['productUrl'] ?? '');
            $res['image'] = (string) ($it['imageUrl'] ?? '');
        }
        $res['total'] = $organic;

        return $res;
    }

    /** 상품 식별 — id 가 있으면 id 로, 없으면 업체명 부분일치(NaverShopExposureService 와 같은 규칙). */
    private function matches(array $it, string $idKind, string $pid, string $mall): bool
    {
        if ($pid !== '') {
            return $idKind === 'nvmid'
                ? (string) ($it['nvMid'] ?? '') === $pid
                : (string) ($it['channelProductId'] ?? '') === $pid;
        }

        return $mall !== '' && str_contains($this->norm((string) ($it['mallName'] ?? '')), $mall);
    }

    private function norm(string $s): string
    {
        return str_replace(' ', '', mb_strtolower(trim($s)));
    }

    private function cfg(): array
    {
        return (array) config('rankfree.shopping.server_collect', []);
    }

    /** node 스크립트 실행 → 마지막 JSON 줄 파싱. 리눅스는 xvfb 로 감싼다(headless 는 네이버가 차단). */
    private function runNode(array $args, array $env, int $timeout): ?array
    {
        $cfg = $this->cfg();
        $cmd = array_merge([(string) ($cfg['node'] ?? 'node')], $args);
        $line = implode(' ', array_map(fn ($a) => escapeshellarg($a), $cmd));
        if (($xvfb = trim((string) ($cfg['xvfb'] ?? ''))) !== '') {
            $line = $xvfb.' '.$line;
        }

        try {
            $res = Process::timeout($timeout)->env(array_filter($env + [
                'SHOP_RANK_PROFILE' => (string) ($cfg['profile'] ?? ''),
                'RANKFREE_PLAYWRIGHT' => (string) ($cfg['playwright'] ?? ''),
            ], fn ($v) => $v !== ''))->run($line);
        } catch (Throwable) {
            return null;
        }

        foreach (array_reverse(explode("\n", trim($res->output()))) as $l) {
            $l = trim($l);
            if (str_starts_with($l, '{') && str_contains($l, '"ok"')) {
                $j = json_decode($l, true);
                if (is_array($j)) {
                    return $j;
                }
            }
        }

        return null;
    }
}
