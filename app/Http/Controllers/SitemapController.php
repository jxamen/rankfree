<?php

namespace App\Http\Controllers;

use App\Models\CommunityCategory;
use App\Models\CommunityPost;
use Illuminate\Support\Facades\Cache;

/** sitemap.xml — 공개 페이지 + 커뮤니티(카테고리·글). 1시간 캐시. robots.txt 의 Sitemap 지시자가 가리킨다. */
class SitemapController extends Controller
{
    public function index()
    {
        $xml = Cache::remember('sitemap:xml', now()->addHour(), function () {
            $urls = [];
            $add = function (string $loc, ?string $lastmod = null, string $freq = 'weekly', string $priority = '0.7') use (&$urls) {
                $urls[] = ['loc' => $loc, 'lastmod' => $lastmod, 'freq' => $freq, 'priority' => $priority];
            };

            // 정적 공개 페이지 (/rank-check 는 302 리다이렉트라 제외)
            $add(route('home'), null, 'daily', '1.0');
            $add(route('self-marketing'), null, 'weekly', '0.7');
            $add(route('support'), null, 'monthly', '0.6');
            $add(url('/developers'), null, 'monthly', '0.6');
            $add(url('/privacy'), null, 'yearly', '0.3');
            $add(route('login'), null, 'monthly', '0.3');
            $add(route('register'), null, 'monthly', '0.5');

            // 커뮤니티 — 전체 + 카테고리별 목록
            $add(route('community'), null, 'hourly', '0.8');
            foreach (CommunityCategory::where('is_active', true)->orderBy('sort_order')->get() as $cat) {
                $add(route('community', ['cat' => $cat->slug]), null, 'daily', '0.6');
            }
            // 커뮤니티 글(최신 우선, 상한 5000)
            CommunityPost::latest('id')->limit(5000)->get(['id', 'updated_at'])->each(function ($p) use ($add) {
                $add(route('community.show', $p), optional($p->updated_at)->toDateString(), 'monthly', '0.5');
            });

            $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
            foreach ($urls as $u) {
                $out .= "  <url><loc>".e($u['loc'])."</loc>";
                if ($u['lastmod']) {
                    $out .= '<lastmod>'.$u['lastmod'].'</lastmod>';
                }
                $out .= '<changefreq>'.$u['freq'].'</changefreq><priority>'.$u['priority']."</priority></url>\n";
            }

            return $out.'</urlset>';
        });

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /** 커뮤니티 RSS 2.0 — 네이버 서치어드바이저는 sitemap 과 별도로 RSS 제출을 지원(국내 수집에 유리). 최신 50건, 30분 캐시. */
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
