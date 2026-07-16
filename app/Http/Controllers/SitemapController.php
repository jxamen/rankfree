<?php

namespace App\Http\Controllers;

use App\Console\Commands\SitemapRefresh;
use App\Models\CommunityCategory;
use App\Models\CommunityPost;
use App\Models\MarketingProduct;
use App\Models\ProductType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * 사이트맵 — /sitemap.xml 은 사이트맵 인덱스, /sitemap-{section}.xml 이 실제 URL 목록.
 *   섹션: pages · community · products · 분석 슬러그(keyword/market/product/seller/store/place/shopping/compete)
 * 분석 공유링크는 SEO 슬러그(/keyword/여름브라 등). 대량 대비 chunk 로 ?page= 분할.
 * 캐시는 sitemap:refresh 가 올리는 버전(SitemapRefresh::version())으로 무효화한다.
 */
class SitemapController extends Controller
{
    private function chunkSize(): int
    {
        return max(1000, (int) config('sitemap.chunk', 20000));
    }

    /** 사이트맵 인덱스. */
    public function index()
    {
        $xml = Cache::remember($this->cacheKey('index'), now()->addHours(6), function () {
            $children = [];
            $this->pushPaged($children, 'pages', 1);
            $this->pushPaged($children, 'community', CommunityPost::count());
            $this->pushPaged($children, 'products', 1);
            if (config('sitemap.include_analyses')) {
                foreach ($this->analysisSections() as $name => $def) {
                    $this->pushPaged($children, $name, ($def['query'])()->count());
                }
            }

            $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
                .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
            foreach ($children as $c) {
                $loc = url('/sitemap-'.$c['section'].'.xml').($c['page'] > 1 ? '?page='.$c['page'] : '');
                $out .= '  <sitemap><loc>'.e($loc).'</loc></sitemap>'."\n";
            }

            return $out.'</sitemapindex>';
        });

        return $this->xml($xml);
    }

    /** 개별 섹션 사이트맵 — /sitemap-{section}.xml?page=N */
    public function section(string $section, Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        abort_unless($this->isKnownSection($section), 404);

        $xml = Cache::remember($this->cacheKey("{$section}:{$page}"), now()->addHours(6), function () use ($section, $page) {
            return $this->renderUrlset($this->entries($section, $page));
        });

        return $this->xml($xml);
    }

    // ── 섹션별 URL 엔트리 ───────────────────────────────────────────────

    /** @return array<int,array{loc:string,lastmod:?string,freq:string,priority:string}> */
    private function entries(string $section, int $page): array
    {
        if ($section === 'pages') {
            return $this->pageEntries();
        }
        if ($section === 'community') {
            return $this->communityEntries($page);
        }
        if ($section === 'products') {
            return $this->productEntries();
        }

        // 분석 슬러그 섹션
        $def = $this->analysisSections()[$section] ?? null;
        if (! $def || ! config('sitemap.include_analyses')) {
            return [];
        }
        $rows = ($def['query'])()->orderBy('id')->forPage($page, $this->chunkSize())->get(['id', 'slug', 'updated_at']);

        return $rows->map(fn ($r) => [
            'loc' => url('/'.$def['prefix']).'/'.rawurlencode($r->slug),
            'lastmod' => optional($r->updated_at)->toDateString(),
            'freq' => $def['freq'],
            'priority' => $def['priority'],
        ])->all();
    }

    private function pageEntries(): array
    {
        $e = [];
        $add = function (string $loc, string $freq, string $priority) use (&$e) {
            $e[] = ['loc' => $loc, 'lastmod' => null, 'freq' => $freq, 'priority' => $priority];
        };
        $add(route('home'), 'daily', '1.0');
        $add(route('self-marketing'), 'weekly', '0.7');
        $add(route('community'), 'hourly', '0.8');
        $add(route('support'), 'monthly', '0.6');
        $add(url('/developers'), 'monthly', '0.6');
        $add(url('/privacy'), 'yearly', '0.3');
        $add(route('register'), 'monthly', '0.5');
        foreach (CommunityCategory::where('is_active', true)->orderBy('sort_order')->get() as $cat) {
            $add(route('community', ['cat' => $cat->slug]), 'daily', '0.6');
        }

        return $e;
    }

    private function communityEntries(int $page): array
    {
        return CommunityPost::orderByDesc('id')
            ->forPage($page, $this->chunkSize())
            ->get(['id', 'updated_at'])
            ->map(fn ($p) => [
                'loc' => route('community.show', $p),
                'lastmod' => optional($p->updated_at)->toDateString(),
                'freq' => 'monthly',
                'priority' => '0.5',
            ])->all();
    }

    private function productEntries(): array
    {
        $e = [['loc' => route('self-marketing'), 'lastmod' => null, 'freq' => 'weekly', 'priority' => '0.7']];
        // 유형(카테고리) 필터 URL — 실제 상품이 있는 유형만
        $codes = MarketingProduct::where('is_active', true)->distinct()->pluck('product_type');
        $names = ProductType::whereIn('code', $codes)->pluck('code');
        foreach ($names as $code) {
            $e[] = ['loc' => route('self-marketing', ['type' => $code]), 'lastmod' => null, 'freq' => 'weekly', 'priority' => '0.6'];
        }

        return $e;
    }

    // ── 분석 섹션 정의 ─────────────────────────────────────────────────

    /**
     * 사이트맵에 공개할 분석 섹션 — config('sitemap.analyses')(1회성 분석만).
     * ⚠️ 순위 추적(/place·/shopping)·경쟁분석(/compete)은 사용자 추적 대상이라 제외.
     */
    private function analysisSections(): array
    {
        $out = [];
        foreach (config('sitemap.analyses', []) as $key => $c) {
            $model = $c['model'];
            $out[$key] = [
                'prefix' => (new $model)->shareSlugPrefix(),
                'freq' => $c['freq'],
                'priority' => $c['priority'],
                'query' => fn () => $model::whereNotNull('slug'),
            ];
        }

        return $out;
    }

    private function isKnownSection(string $section): bool
    {
        return in_array($section, ['pages', 'community', 'products'], true)
            || array_key_exists($section, $this->analysisSections());
    }

    // ── 렌더 헬퍼 ─────────────────────────────────────────────────────

    private function pushPaged(array &$children, string $section, int $count): void
    {
        $pages = max(1, (int) ceil($count / $this->chunkSize()));
        // pages/products/community 는 항상 1페이지 노출, 분석 섹션은 데이터 없으면 생략
        if ($count === 0 && ! in_array($section, ['pages', 'community', 'products'], true)) {
            return;
        }
        for ($p = 1; $p <= $pages; $p++) {
            $children[] = ['section' => $section, 'page' => $p];
        }
    }

    private function renderUrlset(array $entries): string
    {
        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($entries as $u) {
            $out .= '  <url><loc>'.e($u['loc']).'</loc>';
            if (! empty($u['lastmod'])) {
                $out .= '<lastmod>'.$u['lastmod'].'</lastmod>';
            }
            $out .= '<changefreq>'.$u['freq'].'</changefreq><priority>'.$u['priority']."</priority></url>\n";
        }

        return $out.'</urlset>';
    }

    private function cacheKey(string $suffix): string
    {
        return 'sitemap:v'.SitemapRefresh::version().':'.$suffix;
    }

    private function xml(string $body)
    {
        return response($body, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /** 커뮤니티 RSS 2.0 — 네이버 서치어드바이저 제출용. 최신 50건, 30분 캐시. */
    public function communityFeed()
    {
        $xml = Cache::remember('sitemap:community-rss', now()->addMinutes(30), function () {
            $items = '';
            foreach (CommunityPost::with('category')->latest('id')->limit(50)->get() as $p) {
                $desc = mb_substr(preg_replace('/\s+/u', ' ', trim(strip_tags($p->bodyHtml()))), 0, 300);
                $items .= '  <item>'
                    .'<title>'.e($p->title).'</title>'
                    .'<link>'.e(route('community.show', $p)).'</link>'
                    .'<guid isPermaLink="true">'.e(route('community.show', $p)).'</guid>'
                    .'<description>'.e($desc).'</description>'
                    .($p->category ? '<category>'.e($p->category->name).'</category>' : '')
                    .'<pubDate>'.optional($p->created_at)->toRssString().'</pubDate>'
                    ."</item>\n";
            }

            return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
                .'<rss version="2.0"><channel>'
                .'<title>랭크프리 커뮤니티</title>'
                .'<link>'.e(route('community')).'</link>'
                .'<description>네이버 마케팅 노하우·순위 정보 게시판</description>'
                .'<language>ko</language>'."\n"
                .$items
                .'</channel></rss>';
        });

        return response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }
}
