<?php

namespace App\Console\Commands;

use App\Models\CafeCrawlArticle;
use App\Models\CafeCrawlComment;
use App\Models\CommunitySeed;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/**
 * 카페 글감 수집 — node 크롤러 실행(또는 기존 JSON 임포트) → cafe_crawl_* 저장 → (--seed) 글밥 전환.
 * 스케줄러가 매일 실행. 수집 세션(카페 멤버 계정)은 scripts/.naver-cafe-profile 에 저장돼 있어야 한다.
 */
class CafeCrawl extends Command
{
    protected $signature = 'cafe:crawl
        {--cafe= : 카페 ID(기본 config rankfree.cafe_crawl.cafe_id)}
        {--max=0 : 수집 글 수 제한(0=전체)}
        {--file= : 크롤러 실행 없이 기존 수집 JSON 파일 임포트}
        {--seed : 임포트 후 미전환분을 커뮤니티 글밥(community_seeds)으로 전환}
        {--seed-category= : post 글밥에 지정할 커뮤니티 카테고리 ID}';

    protected $description = '네이버 카페 인기글·댓글을 수집해 DB에 저장하고 커뮤니티 글밥으로 전환';

    /** 실행 상태 캐시 키 — 어드민 '지금 수집' 버튼이 조회·선점, 완료 시 여기서 해제. */
    public const RUNNING_CACHE = 'cafe-crawl:running';

    public function handle(): int
    {
        Cache::put(self::RUNNING_CACHE, now()->toDateTimeString(), now()->addMinutes(40));
        try {
            return $this->runCrawl();
        } finally {
            Cache::forget(self::RUNNING_CACHE);
        }
    }

    private function runCrawl(): int
    {
        $cafeId = (int) ($this->option('cafe') ?: config('rankfree.cafe_crawl.cafe_id'));

        // 1) 수집 JSON 확보 — 기존 파일 임포트 or node 크롤러 실행
        $file = (string) $this->option('file');
        if ($file === '') {
            $file = storage_path("app/cafe-crawl/latest-{$cafeId}.json");
            $node = (string) config('rankfree.cafe_crawl.node', 'node');
            $script = base_path('scripts/naver-cafe-crawler.cjs');
            $args = [$node, $script, '--cafe', (string) $cafeId, '--out-file', $file];
            if ((int) $this->option('max') > 0) {
                array_push($args, '--max', (string) (int) $this->option('max'));
            }
            $this->line("크롤러 실행 중… (카페 {$cafeId})");
            $res = Process::timeout(3600)->run($args);
            // 진행 로그는 노이즈 — 마지막 요약만 표시
            foreach (array_slice(array_filter(explode("\n", trim($res->output()))), -3) as $l) {
                $this->line('  '.trim($l));
            }
            if (! $res->successful()) {
                $this->error('크롤러 실패: '.mb_substr(trim($res->output()."\n".$res->errorOutput()), -400));

                return self::FAILURE;
            }
        }

        if (! is_file($file)) {
            $this->error("수집 JSON 을 찾을 수 없습니다: {$file}");

            return self::FAILURE;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (! is_array($data) || ! isset($data['articles'])) {
            $this->error('수집 JSON 형식이 올바르지 않습니다.');

            return self::FAILURE;
        }

        // 2) DB 임포트(upsert — 재수집분은 수치·본문만 갱신, 시드 연결은 보존)
        $stat = $this->import($cafeId, $data['articles']);
        $this->info("임포트 — 글 신규 {$stat['newArticles']}·갱신 {$stat['updArticles']}, 댓글 신규 {$stat['newComments']}건");

        // 3) 글밥 전환
        if ($this->option('seed')) {
            $s = $this->convertToSeeds($cafeId);
            $this->info("글밥 전환 — post {$s['posts']}건, comment {$s['comments']}건");
        }

        return self::SUCCESS;
    }

    /** 수집 JSON → cafe_crawl_articles/comments upsert. */
    private function import(int $cafeId, array $articles): array
    {
        $stat = ['newArticles' => 0, 'updArticles' => 0, 'newComments' => 0];

        foreach ($articles as $a) {
            if (empty($a['articleId'])) {
                continue;
            }
            $row = CafeCrawlArticle::updateOrCreate(
                ['cafe_id' => $cafeId, 'article_id' => (int) $a['articleId']],
                [
                    'title' => mb_substr((string) ($a['title'] ?? ''), 0, 300),
                    // 본문은 새 값이 있을 때만 덮어씀(세션 만료 재수집 등으로 null 이 오면 기존 보존)
                    ...(filled($a['body'] ?? null) ? ['body' => (string) $a['body']] : []),
                    'writer' => mb_substr((string) ($a['writer'] ?? ''), 0, 80),
                    'wrote_at' => $this->kstToUtc($a['writeDate'] ?? null),
                    'read_count' => (int) ($a['readCount'] ?? 0),
                    'comment_count' => (int) ($a['commentCount'] ?? 0),
                    'url' => mb_substr((string) ($a['url'] ?? ''), 0, 255),
                    'crawled_at' => now(),
                ],
            );
            $row->wasRecentlyCreated ? $stat['newArticles']++ : $stat['updArticles']++;

            $comments = (array) ($a['comments'] ?? []);
            if (! $comments) {
                continue;
            }
            $existing = CafeCrawlComment::where('crawl_article_id', $row->id)->pluck('comment_id')->flip();
            $batch = [];
            $seen = []; // 동일 payload 내 중복(과거 수집분) 가드
            foreach ($comments as $c) {
                $cid = (int) ($c['commentId'] ?? 0);
                if (! $cid || isset($existing[$cid]) || isset($seen[$cid])) {
                    continue;
                }
                $seen[$cid] = true;
                $batch[] = [
                    'crawl_article_id' => $row->id,
                    'comment_id' => $cid,
                    'parent_comment_id' => $c['parentId'] ?: null,
                    'writer' => mb_substr((string) ($c['writer'] ?? ''), 0, 80),
                    'content' => (string) ($c['content'] ?? ''),
                    'wrote_at' => $this->kstToUtc($c['writeDate'] ?? null),
                    'is_deleted' => ! empty($c['isDeleted']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            foreach (array_chunk($batch, 200) as $chunk) {
                CafeCrawlComment::insert($chunk);
                $stat['newComments'] += count($chunk);
            }
        }

        return $stat;
    }

    /** 미전환 수집분 → community_seeds. 글은 본문 있는 것만, 댓글은 최소 길이 이상·미삭제만. */
    private function convertToSeeds(int $cafeId): array
    {
        $categoryId = $this->option('seed-category') ? (int) $this->option('seed-category') : null;
        $minLen = (int) config('rankfree.cafe_crawl.seed_min_comment_length', 8);
        $made = ['posts' => 0, 'comments' => 0];

        CafeCrawlArticle::where('cafe_id', $cafeId)->whereNull('seed_id')
            ->whereNotNull('body')->where('body', '!=', '')
            ->orderBy('id')->chunkById(100, function ($rows) use ($categoryId, &$made) {
                foreach ($rows as $row) {
                    $seed = CommunitySeed::create([
                        'kind' => 'post',
                        'category_id' => $categoryId,
                        'title' => mb_substr($row->title, 0, 200),
                        'body' => $row->body,
                        'source' => mb_substr("cafe:{$row->cafe_id}:{$row->article_id}", 0, 80),
                        'is_active' => true,
                    ]);
                    $row->update(['seed_id' => $seed->id, 'seeded_at' => now()]);
                    $made['posts']++;
                }
            });

        CafeCrawlComment::whereNull('seed_id')->where('is_deleted', false)
            ->whereHas('article', fn ($q) => $q->where('cafe_id', $cafeId))
            ->with('article:id,cafe_id,article_id')
            ->orderBy('id')->chunkById(200, function ($rows) use ($categoryId, $minLen, &$made) {
                foreach ($rows as $row) {
                    if (mb_strlen(trim($row->content)) < $minLen) {
                        continue;
                    }
                    $seed = CommunitySeed::create([
                        'kind' => 'comment',
                        'category_id' => $categoryId,
                        'body' => $row->content,
                        'source' => mb_substr("cafe:{$row->article->cafe_id}:{$row->article->article_id}:c{$row->comment_id}", 0, 80),
                        'is_active' => true,
                    ]);
                    $row->update(['seed_id' => $seed->id, 'seeded_at' => now()]);
                    $made['comments']++;
                }
            });

        return $made;
    }

    /** 크롤러의 KST 'Y-m-d H:i:s' → UTC Carbon(앱 타임존). */
    private function kstToUtc(?string $s): ?Carbon
    {
        if (! $s) {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m-d H:i:s', $s, 'Asia/Seoul')->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
