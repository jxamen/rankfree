<?php

namespace App\Domain\Blog;

use Illuminate\Support\Facades\Cache;

/**
 * 네이버 블로그 지수 분석기 — 마케팅 > 블로그 지수 분석(console.blog).
 * ─────────────────────────────────────────────────────────────────────
 * (B) blogId  → 프로필 지표 + 게시물 품질(최근 글 병렬 분석) + 종합 지수/등급.
 * (A) 키워드   → openapi 블로그 검색 상위 블로거 N명을 **전부 병렬로** 각각 분석.
 *
 * 성능: 프로필/글목록/개별글을 curl_multi(동시 16)로 한 번에 병렬 수집.
 *       키워드 분석은 블로거들의 모든 요청을 2페이즈(프로필·글목록 → 게시물글)로 묶어 병렬화.
 * 데이터 소스(무로그인 공개 엔드포인트): m.blog api/blogs · NVisitorgp4Ajax · PostTitleListAsync · m.blog 개별글 · openapi blog.json
 * 점수·등급은 관측 신호 기반 자체 추정치("네이버 공식 지수 아님").
 */
class BlogIndexAnalyzer
{
    private string $ua;
    private string $mobileUa;

    private const CONC = 32; // 동시 병렬 요청 수(롤링)

    public function __construct()
    {
        $this->ua = (string) config('rankfree.place.ua', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36');
        $this->mobileUa = 'Mozilla/5.0 (Linux; Android 12) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36';
    }

    /** URL/blogId 어떤 형태든 blogId 추출. */
    public function blogIdFrom(string $s): string
    {
        $s = trim($s);
        if (preg_match('#blog\.naver\.com/([A-Za-z0-9_\-]+)#', $s, $m) && ! in_array($m[1], ['PostList.naver', 'api'], true)) {
            return $m[1];
        }
        if (preg_match('#blogId=([A-Za-z0-9_\-]+)#', $s, $m)) {
            return $m[1];
        }

        return preg_match('/^[A-Za-z0-9_\-]{2,40}$/', $s) ? $s : '';
    }

    /** (B) 블로그 종합 분석. */
    public function analyzeBlog(string $blogId, int $postN = 30, string $keyword = ''): ?array
    {
        $blogId = $this->blogIdFrom($blogId);
        if ($blogId === '') {
            return null;
        }
        $b = rawurlencode($blogId);
        $ref = 'https://blog.naver.com/PostList.naver?blogId='.$b;

        // Phase 1: 프로필 + 방문 + 글목록 병렬
        $h = $this->multiGetRetry([
            'api' => ['https://m.blog.naver.com/api/blogs/'.$b, 'https://m.blog.naver.com/'.$b, $this->ua],
            'vis' => ['https://blog.naver.com/NVisitorgp4Ajax.nhn?blogId='.$b, $ref, $this->ua],
            'list' => ['https://blog.naver.com/PostTitleListAsync.naver?blogId='.$b.'&viewdate=&currentPage=1&categoryNo=&parentCategoryNo=&countPerPage='.$postN, $ref, $this->ua],
        ]);
        $base = $this->parseBase($blogId, $h['api'] ?? '', $h['vis'] ?? '', $h['list'] ?? '', $postN);
        if (! $base) {
            return null;
        }

        // Phase 2: 개별 글 병렬
        $postReqs = [];
        foreach ($base['logNos'] as $ln) {
            $postReqs['q_'.$ln] = ['https://m.blog.naver.com/'.$b.'/'.$ln, 'https://m.blog.naver.com/'.$b, $this->mobileUa];
        }
        $ph = $postReqs ? $this->multiGetRetry($postReqs) : [];
        $quality = $this->aggregateQuality($ph, $base['rows'], $keyword);

        // 글별 품질(사진·본문·영상) + 공감을 최근 글 행에 주입
        $symp = $this->fetchSympathy($blogId, $base['logNos']);
        foreach ($base['rows'] as $i => $r) {
            $pp = $quality['per_post'][$r['no']] ?? null;
            $base['rows'][$i]['photos'] = $pp['photos'] ?? null;
            $base['rows'][$i]['length'] = $pp['length'] ?? null;
            $base['rows'][$i]['video'] = $pp['video'] ?? null;
            $base['rows'][$i]['sympathy'] = $symp[$r['no']] ?? null;
        }

        return $this->assemble($base, $quality);
    }

    /**
     * (A) 키워드 → 블로그 검색에 노출된 글들을 순서 그대로 병렬 분석.
     * 블로거를 **합치지 않고** 검색 결과에 나오는 글 하나하나를 1행으로 분석(노출 자체가 품질의 방증).
     * 프로필/방문/글목록은 유니크 blogId만 조회해 재사용 → 요청·부하 절감.
     * 전문성(형태소)은 해당 블로그 글목록 제목 전체 + 노출 글 본문으로 계산.
     */
    public function analyzeKeyword(string $keyword, int $bloggerN = 30, int $postN = 30, int $start = 1): array
    {
        $hits = $this->searchBlogPosts($keyword, $bloggerN, $start);
        if (! $hits) {
            return ['keyword' => $keyword, 'bloggers' => []];
        }
        $uids = array_values(array_unique(array_column($hits, 'blogId')));

        // Phase 1: 유니크 blogId 프로필+방문+글목록 병렬(중복 blogId는 1번만)
        $reqs = [];
        foreach ($uids as $bid) {
            $b = rawurlencode($bid);
            $ref = 'https://blog.naver.com/PostList.naver?blogId='.$b;
            $reqs['api|'.$bid] = ['https://m.blog.naver.com/api/blogs/'.$b, 'https://m.blog.naver.com/'.$b, $this->ua];
            $reqs['vis|'.$bid] = ['https://blog.naver.com/NVisitorgp4Ajax.nhn?blogId='.$b, $ref, $this->ua];
            $reqs['list|'.$bid] = ['https://blog.naver.com/PostTitleListAsync.naver?blogId='.$b.'&viewdate=&currentPage=1&categoryNo=&parentCategoryNo=&countPerPage='.$postN, $ref, $this->ua];
        }
        $h1 = $this->multiGetRetry($reqs);

        $bases = [];
        foreach ($uids as $bid) {
            $base = $this->parseBase($bid, $h1['api|'.$bid] ?? '', $h1['vis|'.$bid] ?? '', $h1['list|'.$bid] ?? '', $postN);
            if ($base) {
                $bases[$bid] = $base;
            }
        }

        // Phase 2: 검색 결과 각 글(노출 글)을 병렬 수집 — hit 인덱스로 키 구분(같은 글 중복 방지)
        $postReqs = [];
        foreach ($hits as $i => $ht) {
            $bid = $ht['blogId'];
            $ln = $ht['logNo'] ?? '';
            if (isset($bases[$bid]) && $ln !== '') {
                $postReqs[$i.'|'.$bid.'|'.$ln] = ['https://m.blog.naver.com/'.rawurlencode($bid).'/'.$ln, 'https://m.blog.naver.com/'.rawurlencode($bid), $this->mobileUa];
            }
        }
        $h2 = $postReqs ? $this->multiGetRetry($postReqs) : [];

        // 집계 — 검색 결과 순서 그대로, 글마다 1행
        $out = [];
        $rank = 0;
        foreach ($hits as $i => $ht) {
            $bid = $ht['blogId'];
            if (! isset($bases[$bid])) {
                continue;
            }
            $rank++;
            $base = $bases[$bid];
            $ln = $ht['logNo'] ?? '';
            $htmls = $ln !== '' ? ['q_'.$ln => $h2[$i.'|'.$bid.'|'.$ln] ?? ''] : [];
            $quality = $this->aggregateQuality($htmls, $base['rows'], $keyword);
            // 노출 글 1개라 전문성이 약하므로 글목록 제목으로 보강
            $titles = implode(' ', array_column($base['rows'], 'title'));
            $quality['top_words'] = $this->topWords($titles.' '.$titles.' '.$quality['_body'], 12);
            unset($quality['_body']);
            $r = $this->assemble($base, $quality);
            $r['search_rank'] = $rank;
            $r['featured'] = ['log_no' => $ln, 'title' => $ht['title'] ?? '', 'date' => $ht['postdate'] ?? ''];
            $out[] = $r;
        }

        $display = min(100, max($bloggerN, 10));

        return ['keyword' => $keyword, 'bloggers' => $out, 'next_start' => min(1001, $start + $display)];
    }

    /**
     * openapi 블로그 검색 → 검색에 노출된 글 [blogId, logNo, 제목, 발행일] 목록.
     * 블로거를 **합치지 않고** 검색 결과에 나오는 글 순서 그대로 반환(같은 블로거가 여러 번 노출되면 각각 1행).
     */
    public function searchBlogPosts(string $keyword, int $limit = 30, int $start = 1): array
    {
        $keys = (array) config('rankfree.shopping.api_keys');
        if (! $keys) {
            return [];
        }
        $start = max(1, min(1000, $start));
        foreach ($keys as $k) {
            $body = $this->httpGet(
                'https://openapi.naver.com/v1/search/blog.json?query='.rawurlencode($keyword).'&display='.min(100, max($limit, 10)).'&start='.$start.'&sort=sim',
                '', $this->ua,
                ['X-Naver-Client-Id: '.$k['id'], 'X-Naver-Client-Secret: '.$k['secret']]
            );
            $j = json_decode($body, true);
            if (! isset($j['items'])) {
                continue; // 키 소진 → 다음 키
            }
            $out = [];
            foreach ($j['items'] as $it) {
                $bid = $this->blogIdFrom((string) ($it['bloggerlink'] ?? ''));
                if ($bid === '') {
                    continue;
                }
                // logNo = URL 경로의 마지막 숫자 세그먼트(숫자형 blogId여도 blogId를 오인하지 않음)
                $logNo = '';
                if (preg_match_all('~/(\d{6,})~', (string) ($it['link'] ?? ''), $mm)) {
                    $logNo = end($mm[1]);
                }
                $out[] = ['blogId' => $bid, 'logNo' => $logNo, 'title' => strip_tags((string) ($it['title'] ?? '')), 'postdate' => (string) ($it['postdate'] ?? '')];
                if (count($out) >= $limit) {
                    break;
                }
            }

            return $out;
        }

        return [];
    }

    /**
     * 경량 블로그 등급(프로필 기반) — blogId 여러 개를 한 번에 병렬 조회. 개별 글 크롤 없음(Phase 1만).
     * 인기글 TOP 등 "블로그 등급만 빠르게" 필요할 때 사용. 6시간 캐시.
     *
     * @param  list<string>  $blogIdsOrUrls
     * @return array<string,array{grade:string,score:float,day_visitor:int,subscriber:int,influencer:bool,power:bool}>
     */
    public function quickGrades(array $blogIdsOrUrls): array
    {
        $ids = [];
        foreach ($blogIdsOrUrls as $s) {
            $bid = $this->blogIdFrom((string) $s);
            if ($bid !== '' && ! in_array($bid, $ids, true)) {
                $ids[] = $bid;
            }
        }
        if (! $ids) {
            return [];
        }

        return Cache::remember('blog:qgrade:'.md5(implode(',', $ids)), now()->addHours(6), function () use ($ids) {
            $reqs = [];
            foreach ($ids as $bid) {
                $b = rawurlencode($bid);
                $ref = 'https://blog.naver.com/PostList.naver?blogId='.$b;
                $reqs['api|'.$bid] = ['https://m.blog.naver.com/api/blogs/'.$b, 'https://m.blog.naver.com/'.$b, $this->ua];
                $reqs['vis|'.$bid] = ['https://blog.naver.com/NVisitorgp4Ajax.nhn?blogId='.$b, $ref, $this->ua];
                $reqs['list|'.$bid] = ['https://blog.naver.com/PostTitleListAsync.naver?blogId='.$b.'&viewdate=&currentPage=1&categoryNo=&parentCategoryNo=&countPerPage=10', $ref, $this->ua];
            }
            $h = $this->multiGetRetry($reqs);
            $out = [];
            foreach ($ids as $bid) {
                $base = $this->parseBase($bid, $h['api|'.$bid] ?? '', $h['vis|'.$bid] ?? '', $h['list|'.$bid] ?? '', 10);
                if ($base) {
                    $out[$bid] = $this->profileGrade($base);
                }
            }

            return $out;
        });
    }

    /** 프로필 신호(활동·댓글·방문·이웃·글축적)만으로 등급 산출 — 게시물 품질 제외 경량판. */
    private function profileGrade(array $base): array
    {
        $p = $base['profile'];
        $sAct = min(1, $base['post_per_week'] / 5) * 100;
        $sCmt = min(1, $base['avg_comment'] / 30) * 100;
        $sVis = min(1, $base['day_visitor_avg'] / 1000) * 100;
        $sSub = min(1, ($p['subscriber_cnt'] ?? 0) / 1000) * 100;
        $sVol = min(1, $base['post_total'] / 700) * 100;
        $bonus = (($p['power_blog'] ?? false) ? 6 : 0) + (($p['influencer'] ?? false) ? 4 : 0);
        $score = min(100, round(0.26 * $sAct + 0.18 * $sCmt + 0.22 * $sVis + 0.24 * $sSub + 0.10 * $sVol + $bonus, 1));
        $grade = match (true) {
            $score >= 80 => 'S',
            $score >= 65 => 'A',
            $score >= 50 => 'B',
            $score >= 35 => 'C',
            default => 'D',
        };

        return [
            'grade' => $grade,
            'score' => $score,
            'day_visitor' => $base['day_visitor_avg'],
            'subscriber' => (int) ($p['subscriber_cnt'] ?? 0),
            'influencer' => ! empty($p['influencer']),
            'power' => ! empty($p['power_blog']),
        ];
    }

    // ── 파싱·집계 ─────────────────────────────────────────────────────

    /** 프로필+방문+글목록 응답 → 기초 지표(개별 글 크롤 전). @return array|null */
    private function parseBase(string $blogId, string $apiH, string $visH, string $listH, int $postN): ?array
    {
        $api = json_decode($apiH, true);
        if (empty($api['isSuccess']) || empty($api['result'])) {
            return null;
        }
        $a = $api['result'];
        $ref = date('Y-m-d');
        $profile = [
            'blog_id' => $blogId,
            'blog_name' => $a['blogName'] ?? '',
            'nick_name' => $a['nickName'] ?? '',
            'directory' => $a['blogDirectoryName'] ?? '',
            'power_blog' => ! empty($a['powerBlog']),
            'subscriber_cnt' => isset($a['subscriberCount']) ? (int) $a['subscriberCount'] : 0,
            'total_visitor' => isset($a['totalVisitorCount']) ? (int) $a['totalVisitorCount'] : 0,
            'influencer' => ! empty($a['influencer']) || ! empty($a['isInfluencer']),
        ];

        $dayVisitorAvg = 0;
        $visitor5 = [];
        if (preg_match_all('/id="(\d{8})"\s+cnt="(\d+)"/', $visH, $vm, PREG_SET_ORDER)) {
            foreach ($vm as $m) {
                $visitor5[] = ['date' => $m[1], 'count' => (int) $m[2]];
            }
            $cs = array_column($visitor5, 'count');
            $full = array_slice($cs, 0, max(1, count($cs) - 1)); // 마지막(오늘, 집계중) 제외
            $dayVisitorAvg = (int) round(array_sum($full) / max(1, count($full)));
        }

        $p1 = $this->postsDecode($listH);
        if (! $p1 || empty($p1['postList'])) {
            return null;
        }
        $postTotal = (int) $p1['totalCount'];
        $rows = [];
        $dates = [];
        $catCount = [];
        $cSum = 0;
        $cN = 0;
        $rSum = 0;
        $rN = 0;
        foreach ($p1['postList'] as $p) {
            $title = urldecode(str_replace('+', ' ', (string) ($p['title'] ?? '')));
            $ymd = $this->parseAddDate((string) ($p['addDate'] ?? ''), $ref);
            $cm = ($p['commentCount'] ?? '') !== '' ? (int) $p['commentCount'] : null;
            $rc = ($p['readCount'] ?? '') !== '' ? (int) $p['readCount'] : null;
            $cat = (string) ($p['categoryNo'] ?? '');
            if ($cm !== null) {
                $cSum += $cm;
                $cN++;
            }
            if ($rc !== null) {
                $rSum += $rc;
                $rN++;
            }
            if ($ymd) {
                $dates[] = $ymd;
            }
            if ($cat !== '') {
                $catCount[$cat] = ($catCount[$cat] ?? 0) + 1;
            }
            $rows[] = ['no' => (string) ($p['logNo'] ?? ''), 'title' => mb_substr($title, 0, 100), 'date' => $ymd, 'comment' => $cm, 'read' => $rc];
        }
        $postPerWeek = 0.0;
        if (count($dates) >= 2) {
            $span = max(1, (strtotime($ref) - strtotime(min($dates))) / 86400);
            $postPerWeek = round(count($dates) / ($span / 7), 2);
        } elseif (count($dates) === 1) {
            $postPerWeek = 0.25;
        }
        $catTotal = array_sum($catCount) ?: 1;

        return [
            'profile' => $profile + ['post_total' => $postTotal],
            'day_visitor_avg' => $dayVisitorAvg,
            'visitor5' => $visitor5,
            'post_total' => $postTotal,
            'post_per_week' => $postPerWeek,
            'last_post' => $dates ? max($dates) : null,
            'read_avg' => $rN ? (int) round($rSum / $rN) : 0,
            'avg_comment' => $cN ? round($cSum / $cN, 1) : 0,
            'top_focus' => $catCount ? max($catCount) / $catTotal : 0,
            'rows' => $rows,
            'logNos' => array_slice(array_values(array_filter(array_column($rows, 'no'))), 0, $postN),
        ];
    }

    /** base + 게시물 품질 → 최종 결과(지수·등급 포함). */
    private function assemble(array $base, array $quality): array
    {
        unset($quality['_body'], $quality['per_post']); // 내부 계산용 필드는 결과에서 제외
        $p = $base['profile'];
        $index = $this->computeIndex($p, $base['post_per_week'], $base['avg_comment'], $base['day_visitor_avg'], $base['post_total'], $base['read_avg'], $base['top_focus'], $quality);

        return [
            'blog_id' => $p['blog_id'],
            'profile' => $p + [
                'day_visitor_avg' => $base['day_visitor_avg'],
                'visitor5' => $base['visitor5'] ?? [],
                'post_per_week' => $base['post_per_week'],
                'avg_comment' => $base['avg_comment'],
                'read_avg' => $base['read_avg'],
                'last_post' => $base['last_post'],
                'top_focus' => round($base['top_focus'] * 100),
            ],
            'quality' => $quality,
            'posts' => $base['rows'], // 수집한 최근 글 전부(사진·본문·영상·공감 포함)
            'score' => $index['score'],
            'grade' => $index['grade'],
            'breakdown' => $index['breakdown'],
            'fetched_at' => date('Y-m-d'),
        ];
    }

    /** 개별 글 HTML 집합 → 게시물 품질(사진·본문·영상·키워드 적합·전문성 단어). */
    private function aggregateQuality(array $htmls, array $rows, string $keyword): array
    {
        $titleById = [];
        foreach ($rows as $r) {
            $titleById[$r['no']] = $r['title'];
        }
        $n = 0;
        $photoSum = 0;
        $lenSum = 0;
        $videoN = 0;
        $kwTitle = 0;
        $kwBody = 0;
        $bodyAll = '';
        $titleAll = '';
        $perPost = []; // logNo => [photos, length, video] — 글별 품질(최근 글 표에 표기)
        $kwNorm = $keyword !== '' ? preg_replace('/\s+/u', '', mb_strtolower($keyword)) : '';
        foreach ($htmls as $key => $html) {
            if (! is_string($html) || $html === '') {
                continue;
            }
            $logNo = str_starts_with((string) $key, 'q_') ? substr((string) $key, 2) : (string) $key;
            $photos = preg_match_all('/class="se-image-resource"/', $html);
            $body = '';
            if (preg_match_all('/<div class="se-module se-module-text[^"]*">(.*?)<\/div>/s', $html, $mt)) {
                $body = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(implode(' ', $mt[1]))));
            }
            $len = mb_strlen(trim($body));
            if ($photos === 0 && $len === 0) {
                continue;
            }
            $n++;
            $photoSum += $photos;
            $lenSum += $len;
            $bodyAll .= ' '.$body;
            $titleAll .= ' '.($titleById[$logNo] ?? '');
            $hasVideo = str_contains($html, 'se-module-video') || str_contains($html, 'se-video');
            if ($hasVideo) {
                $videoN++;
            }
            $perPost[$logNo] = ['photos' => (int) $photos, 'length' => $len, 'video' => $hasVideo ? 1 : 0];
            if ($kwNorm !== '') {
                $title = preg_replace('/\s+/u', '', mb_strtolower($titleById[$logNo] ?? ''));
                if ($title !== '' && str_contains($title, $kwNorm)) {
                    $kwTitle++;
                }
                if (str_contains(preg_replace('/\s+/u', '', mb_strtolower($body)), $kwNorm)) {
                    $kwBody++;
                }
            }
        }

        return [
            'analyzed' => $n,
            'avg_photos' => $n ? round($photoSum / $n, 1) : 0,
            'avg_length' => $n ? (int) round($lenSum / $n) : 0,
            'video_ratio' => $n ? round($videoN / $n * 100) : 0,
            'kw_title_ratio' => ($kwNorm !== '' && $n) ? round($kwTitle / $n * 100) : null,
            'kw_body_ratio' => ($kwNorm !== '' && $n) ? round($kwBody / $n * 100) : null,
            'top_words' => $this->topWords($titleAll.' '.$titleAll.' '.$bodyAll, 15),
            'per_post' => $perPost,
            '_body' => trim($bodyAll),
        ];
    }

    /**
     * 글별 공감(반응) 수 배치 조회 — 네이버 반응 API. "공감"은 좋아요·칭찬해요 등 반응 합.
     *
     * @param  array<int,string>  $logNos
     * @return array<string,int>  logNo => 공감 합
     */
    private function fetchSympathy(string $blogId, array $logNos): array
    {
        if (! $logNos) {
            return [];
        }
        // 반응 API는 콤마 배치가 첫 건만 반환 → 글마다 개별 요청을 병렬로.
        $ref = 'https://m.blog.naver.com/'.rawurlencode($blogId);
        $reqs = [];
        foreach ($logNos as $ln) {
            $q = 'BLOG['.$blogId.'_'.$ln.']';
            $reqs[(string) $ln] = ['https://apis.naver.com/blogserver/like/v1/search/contents?suppress_response_codes=true&q='.rawurlencode($q).'&isDuplication=false', $ref, $this->ua];
        }
        $out = [];
        foreach ($this->multiGetRetry($reqs) as $ln => $body) {
            $j = json_decode((string) $body, true);
            $c = $j['contents'][0] ?? null;
            if (! $c) {
                continue;
            }
            $sum = 0;
            foreach ($c['reactions'] ?? [] as $rx) {
                $sum += (int) ($rx['count'] ?? 0);
            }
            $out[(string) $ln] = $sum;
        }

        return $out;
    }

    /** 종합 지수(0~100) + 등급 + 세부 점수. */
    private function computeIndex(array $profile, float $postPerWeek, float $avgComment, int $dayVisitorAvg, int $postTotal, int $readAvg, float $topFocus, array $quality): array
    {
        // 프로필 지수 — 활동·반응·방문·이웃·활동규모(글 축적)
        $sAct = min(1, $postPerWeek / 5) * 100;
        $sCmt = min(1, $avgComment / 30) * 100;
        $sVis = min(1, $dayVisitorAvg / 1000) * 100;
        $sSub = min(1, ($profile['subscriber_cnt'] ?? 0) / 1000) * 100;
        $sVol = min(1, $postTotal / 700) * 100; // 개설일 대신 글 축적량(신뢰도 높음)
        $profileScore = 0.26 * $sAct + 0.18 * $sCmt + 0.22 * $sVis + 0.24 * $sSub + 0.10 * $sVol;

        // 콘텐츠(게시물 품질)
        $qPhoto = min(1, ($quality['avg_photos'] ?? 0) / 12) * 100;
        $qText = min(1, ($quality['avg_length'] ?? 0) / 1500) * 100;
        $qVideo = min(1, ($quality['video_ratio'] ?? 0) / 100) * 100;
        $contentScore = 0.5 * $qPhoto + 0.4 * $qText + 0.1 * $qVideo;

        $focusScore = $topFocus * 100;

        $bonus = (($profile['power_blog'] ?? false) ? 6 : 0) + (($profile['influencer'] ?? false) ? 4 : 0);
        $score = min(100, round(0.40 * $profileScore + 0.35 * $contentScore + 0.15 * $focusScore + $bonus, 1));

        $grade = match (true) {
            $score >= 80 => 'S',
            $score >= 65 => 'A',
            $score >= 50 => 'B',
            $score >= 35 => 'C',
            default => 'D',
        };

        return [
            'score' => $score,
            'grade' => $grade,
            'breakdown' => [
                'profile' => round($profileScore), 'content' => round($contentScore), 'focus' => round($focusScore),
                'activity' => round($sAct), 'comment' => round($sCmt), 'visitor' => round($sVis),
                'subscriber' => round($sSub), 'volume' => round($sVol),
                'photo' => round($qPhoto), 'text' => round($qText),
            ],
        ];
    }

    /**
     * 간이 형태소/전문성 — 조사·어미·불용어 제거한 2자+ 단어의 빈도 상위.
     * 뷰(블로그 상세 최근 글 목록)에서 글별 주제어 표기에도 재사용한다.
     * $minCount: 최소 등장 횟수 — 집계(기본 2) / 짧은 단일 제목은 1로 호출.
     *
     * @return array<int,array{word:string,count:int}>
     */
    public function topWords(string $text, int $top = 15, int $minCount = 2): array
    {
        static $stop = [
            '있는', '없는', '하는', '그리고', '해서', '너무', '진짜', '정말', '후기', '에서', '으로', '까지', '부터',
            '그래서', '합니다', '했어요', '있어요', '있어서', '같아요', '이런', '저런', '정도', '우리', '오늘', '하고',
            '이제', '근데', '바로', '생각', '이렇게', '저렇게', '저는', '제가', '많이', '조금', '다시', '그냥', '역시',
            '경우', '때문', '통해', '위해', '가장', '보다', '더욱', '매우', '항상', '함께', '모두', '여기', '거기',
        ];
        static $josa = ['으로', '에서', '에게', '까지', '부터', '이라', '라고', '이다', '였다', '한다', '하다', '이나', '거나', '든지', '처럼', '만큼', '보다'];

        $cnt = [];
        foreach (preg_split('/[^가-힣A-Za-z0-9]+/u', $text) as $tk) {
            $tk = trim($tk);
            if ($tk === '' || preg_match('/^\d+$/', $tk)) {
                continue;
            }
            if (mb_strlen($tk) >= 3) {
                $tail = mb_substr($tk, -1);
                if (in_array($tail, ['을', '를', '이', '가', '은', '는', '의', '에', '와', '과', '도', '만', '로', '나'], true)) {
                    $tk = mb_substr($tk, 0, -1);
                }
                foreach ($josa as $j) {
                    if (mb_strlen($tk) > mb_strlen($j) + 1 && str_ends_with($tk, $j)) {
                        $tk = mb_substr($tk, 0, -mb_strlen($j));
                        break;
                    }
                }
            }
            if (mb_strlen($tk) < 2 || in_array($tk, $stop, true)) {
                continue;
            }
            $cnt[$tk] = ($cnt[$tk] ?? 0) + 1;
        }
        arsort($cnt);
        $out = [];
        foreach ($cnt as $w => $c) {
            if ($c < $minCount) {
                break;
            }
            $out[] = ['word' => $w, 'count' => $c];
            if (count($out) >= $top) {
                break;
            }
        }

        return $out;
    }

    // ── HTTP ─────────────────────────────────────────────────────────

    /**
     * 롤링 병렬 GET — 동시 $conc개를 유지하며, 하나 끝나면 즉시 다음을 투입.
     * (배치 방식과 달리 느린 요청이 나머지를 막지 않아 전체가 훨씬 빠르다.)
     *
     * @param  array<string,array{0:string,1:string,2:string}>  $reqs  [url, referer, ua]
     */
    /**
     * 롤링 병렬 GET + 빈 응답 재시도.
     * 동시 요청이 많으면 네이버가 일부를 빈 응답으로 흘리므로, 빈 값만 모아 재시도(동시성 낮춰)해 누락을 메운다.
     *
     * @param  array<string,array{0:string,1:string,2:string}>  $reqs
     */
    private function multiGetRetry(array $reqs, int $retries = 2): array
    {
        $out = $this->multiGet($reqs);
        for ($r = 0; $r < $retries; $r++) {
            $miss = [];
            foreach ($reqs as $k => $v) {
                if (($out[$k] ?? '') === '') {
                    $miss[$k] = $v;
                }
            }
            if (! $miss) {
                break;
            }
            // 재시도는 실패분만 — 동시성을 낮춰 차단 회피
            foreach ($this->multiGet($miss, min(8, count($miss))) as $k => $v) {
                if ($v !== '') {
                    $out[$k] = $v;
                }
            }
        }

        return $out;
    }

    private function multiGet(array $reqs, ?int $conc = null): array
    {
        $conc = $conc ?? self::CONC;
        $keys = array_keys($reqs);
        $vals = array_values($reqs);
        $n = count($vals);
        if ($n === 0) {
            return [];
        }
        $out = [];
        $mh = curl_multi_init();
        $map = []; // (int)$ch => key
        $i = 0;

        $add = function () use (&$i, $vals, $keys, $mh, &$map, $n) {
            if ($i >= $n) {
                return;
            }
            $req = $vals[$i];
            $key = $keys[$i];
            $i++;
            $hd = ['accept: */*', 'accept-language: ko-KR,ko;q=0.9', 'user-agent: '.($req[2] ?? $this->ua)];
            if (! empty($req[1])) {
                $hd[] = 'referer: '.$req[1];
            }
            $ch = curl_init($req[0]);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => 1, CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_FOLLOWLOCATION => 1, CURLOPT_MAXREDIRS => 5, CURLOPT_ENCODING => '',
                CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => $hd,
            ]);
            curl_multi_add_handle($mh, $ch);
            $map[(int) $ch] = $key;
        };

        for ($j = 0; $j < min($conc, $n); $j++) {
            $add();
        }
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.5);
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $out[$map[(int) $ch]] = (string) curl_multi_getcontent($ch);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($map[(int) $ch]);
                $add(); // 완료 즉시 새 요청 투입
            }
        } while ($running > 0 || $i < $n);
        curl_multi_close($mh);

        return $out;
    }

    private function httpGet(string $url, string $referer, string $ua, array $extraHeaders = []): string
    {
        $ch = curl_init($url);
        $hd = array_merge(['accept: */*', 'accept-language: ko-KR,ko;q=0.9', 'user-agent: '.$ua], $extraHeaders);
        if ($referer !== '') {
            $hd[] = 'referer: '.$referer;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => 1, CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => 1, CURLOPT_MAXREDIRS => 5, CURLOPT_ENCODING => '',
            CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $hd,
        ]);
        $b = (string) curl_exec($ch);
        curl_close($ch);

        return $b;
    }

    private function postsDecode(string $body): ?array
    {
        if ($body === '') {
            return null;
        }
        $j = json_decode(str_replace("\\'", "'", $body), true);

        return is_array($j) && isset($j['postList']) ? $j : null;
    }

    private function parseAddDate(string $s, string $ref): ?string
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        if (preg_match('/(\d{4})\.\s*(\d{1,2})\.\s*(\d{1,2})/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        if (mb_strpos($s, '어제') !== false) {
            return date('Y-m-d', strtotime($ref.' -1 day'));
        }
        if (preg_match('/(분|시간)\s*전/u', $s)) {
            return $ref;
        }

        return null;
    }
}
