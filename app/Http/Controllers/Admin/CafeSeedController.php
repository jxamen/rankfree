<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\CafeCrawl;
use App\Http\Controllers\Controller;
use App\Models\CafeCrawlArticle;
use App\Models\CafeCrawlComment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** 카페 수집 글감 — 수집 원본(글·댓글) 조회 + 수동 수집 실행. 수집은 cafe:crawl(스케줄러/버튼 공용). */
class CafeSeedController extends Controller
{
    /** 수집 실행 상태 캐시 키(CafeCrawl 명령이 소유·해제). */
    public const RUNNING_CACHE = CafeCrawl::RUNNING_CACHE;

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $state = (string) $request->query('state', '');

        $articles = CafeCrawlArticle::query()
            ->withCount('comments')
            ->with('seed:id,used_count,last_used_at,is_active')
            ->when($q !== '', fn ($w) => $w->where('title', 'like', "%{$q}%"))
            ->when($state === 'seeded', fn ($w) => $w->whereNotNull('seed_id'))
            ->when($state === 'unseeded', fn ($w) => $w->whereNull('seed_id'))
            ->when($state === 'used', fn ($w) => $w->whereHas('seed', fn ($s) => $s->where('used_count', '>', 0)))
            ->orderByDesc('wrote_at')
            ->paginate(30)->withQueryString();

        return view('admin.cafe-seeds.index', [
            'articles' => $articles,
            'q' => $q,
            'state' => $state,
            'stats' => [
                'articles' => CafeCrawlArticle::count(),
                'comments' => CafeCrawlComment::count(),
                'seeded' => CafeCrawlArticle::whereNotNull('seed_id')->count(),
                'lastCrawledAt' => CafeCrawlArticle::max('crawled_at'),
            ],
            'running' => Cache::get(self::RUNNING_CACHE),
        ]);
    }

    public function show(CafeCrawlArticle $article)
    {
        $article->load([
            'seed.usages' => fn ($q) => $q->latest('id')->with(['persona:id,nickname', 'post:id,title']),
            'comments' => fn ($q) => $q->orderBy('wrote_at'),
            'comments.seed:id,used_count,last_used_at',
        ]);

        return view('admin.cafe-seeds.show', ['article' => $article]);
    }

    /** 사용여부 토글 — 연결된 글밥의 is_active 전환. OFF면 재작성 소재로 뽑히지 않는다(CommunitySeed::pick). */
    public function toggleSeed(CafeCrawlArticle $article)
    {
        if (! $article->seed) {
            return back()->with('status', '아직 글밥으로 전환되지 않은 글입니다.');
        }
        $article->seed->update(['is_active' => ! $article->seed->is_active]);

        return back()->with('status', $article->seed->is_active ? '사용함으로 변경했습니다.' : '사용 안 함으로 변경했습니다.');
    }

    /** 수동 수집 — cafe:crawl --seed 를 백그라운드로 실행하고 즉시 돌아온다. */
    public function crawl(Request $request)
    {
        if (Cache::get(self::RUNNING_CACHE)) {
            return back()->with('status', '이미 수집이 진행 중입니다 — 완료까지 몇 분 걸립니다. 잠시 후 새로고침하세요.');
        }
        Cache::put(self::RUNNING_CACHE, now()->toDateTimeString(), now()->addMinutes(40));

        $php = $this->phpBinary();
        $artisan = base_path('artisan');
        $cmd = escapeshellarg($php).' '.escapeshellarg($artisan).' cafe:crawl --seed';

        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start "" /B '.$cmd.' >NUL 2>&1', 'r'));
        } else {
            exec($cmd.' > /dev/null 2>&1 &');
        }

        return back()->with('status', '수집을 시작했습니다 — 인기글 전체 수집에 몇 분 걸립니다. 완료되면 목록에 반영됩니다.');
    }

    /** CLI php 경로 — 웹 SAPI 에서는 PHP_BINARY 가 php 가 아닐 수 있어 보정. */
    private function phpBinary(): string
    {
        $bin = (string) config('rankfree.cafe_crawl.php_bin', '');
        if ($bin !== '') {
            return $bin;
        }
        if (str_contains(strtolower(basename(PHP_BINARY)), 'php')) {
            return PHP_BINARY;
        }

        return PHP_BINDIR.DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
    }
}
