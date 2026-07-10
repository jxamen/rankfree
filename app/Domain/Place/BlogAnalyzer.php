<?php

namespace App\Domain\Place;

use App\Models\BlogProfile;

/**
 * 네이버 블로그 리뷰어 품질 분석 — crm smartplace.blog.lib.php(spb_) 이식.
 * blogId → 이웃수·방문자·활동기간·최근 글(제목/댓글/빈도) 수집·품질 점수화. 무로그인 공개 엔드포인트.
 * 결과는 blog_profiles 에 캐시(기본 168h).
 */
class BlogAnalyzer
{
    private string $ua;

    public function __construct()
    {
        $this->ua = (string) config('rankfree.place.ua');
    }

    /** 여러 블로그 일괄 분석(캐시 우선) — 리뷰어 품질 보강용. @return array<string,array> blogId=>row */
    public function analyzeMany(array $blogIds, int $ttlH = 168, int $limit = 5): array
    {
        $out = [];
        $n = 0;
        foreach (array_unique(array_filter($blogIds)) as $bid) {
            if ($n >= $limit) {
                break;
            }
            $r = $this->analyze($bid, $ttlH);
            if ($r) {
                $out[$bid] = $r;
                $n++;
            }
        }

        return $out;
    }

    /** 캐시 조회(기본 168h) → 만료 시 수집·저장. */
    public function analyze(string $blogId, int $ttlH = 168, bool $force = false): ?array
    {
        $blogId = $this->blogIdFrom($blogId);
        if ($blogId === '') {
            return null;
        }
        $row = BlogProfile::find($blogId);
        if (! $force && $row && $row->fetched_at && $row->fetched_at->timestamp > time() - $ttlH * 3600) {
            return $row->toArray();
        }
        $d = $this->fetch($blogId);
        $d['fetched_at'] = now();
        BlogProfile::updateOrCreate(['blog_id' => $blogId], $d);

        return BlogProfile::find($blogId)?->toArray();
    }

    /** URL/blogId 어떤 형태든 blogId 추출. */
    public function blogIdFrom(string $s): string
    {
        $s = trim($s);
        if (preg_match('#blog\.naver\.com/([A-Za-z0-9_\-]+)#', $s, $m) && $m[1] !== 'PostList.naver' && $m[1] !== 'api') {
            return $m[1];
        }
        if (preg_match('#blogId=([A-Za-z0-9_\-]+)#', $s, $m)) {
            return $m[1];
        }

        return preg_match('/^[A-Za-z0-9_\-]{2,40}$/', $s) ? $s : '';
    }

    /** 수집+파싱(캐시 무시). @return array profile 필드 */
    public function fetch(string $blogId): array
    {
        $ref = date('Y-m-d');
        $bid = rawurlencode($blogId);
        $blogRef = 'https://m.blog.naver.com/'.$bid;
        $listRef = 'https://blog.naver.com/PostList.naver?blogId='.$bid;
        $h = $this->multiGet([
            'api' => ['https://m.blog.naver.com/api/blogs/'.$bid, $blogRef],
            'vis' => ['https://blog.naver.com/NVisitorgp4Ajax.nhn?blogId='.$bid, $listRef],
            'p1' => ['https://blog.naver.com/PostTitleListAsync.naver?blogId='.$bid.'&viewdate=&currentPage=1&categoryNo=&parentCategoryNo=&countPerPage=30', $listRef],
        ], 3);

        $out = ['blog_id' => $blogId, 'ok' => 0];

        // ① 프로필(API)
        $api = json_decode($h['api'] ?? '', true);
        if (! empty($api['isSuccess']) && ! empty($api['result'])) {
            $a = $api['result'];
            $out['blog_name'] = $a['blogName'] ?? '';
            $out['nick_name'] = $a['nickName'] ?? '';
            $out['directory'] = $a['blogDirectoryName'] ?? '';
            $out['power_blog'] = ! empty($a['powerBlog']) ? 1 : 0;
            $out['subscriber_cnt'] = isset($a['subscriberCount']) ? (int) $a['subscriberCount'] : null;
            $out['total_visitor'] = isset($a['totalVisitorCount']) ? (int) $a['totalVisitorCount'] : null;
            $out['ok'] = 1;
        }

        // ② 방문자 5일(XML) — 마지막(오늘, 집계중) 제외 평균
        if (preg_match_all('/id="(\d{8})"\s+cnt="(\d+)"/', $h['vis'] ?? '', $vm, PREG_SET_ORDER)) {
            $v5 = [];
            foreach ($vm as $m) {
                $v5[] = ['d' => $m[1], 'c' => (int) $m[2]];
            }
            $out['visitor5_json'] = json_encode($v5);
            $full = array_slice($v5, 0, max(1, count($v5) - 1));
            $sum = 0;
            foreach ($full as $v) {
                $sum += $v['c'];
            }
            $out['day_visitor_avg'] = (int) round($sum / max(1, count($full)));
        }

        // ③ 글 목록 p1 → 총 글수·최근 활동·댓글
        $p1 = $this->postsDecode($h['p1'] ?? '');
        if ($p1) {
            $total = (int) $p1['totalCount'];
            $out['post_total'] = $total;
            $posts = [];
            $cSum = 0;
            $cN = 0;
            $dates = [];
            foreach ($p1['postList'] as $p) {
                $title = urldecode(str_replace('+', ' ', (string) $p['title']));
                $ymd = $this->parseAddDate((string) $p['addDate'], $ref);
                $cm = ($p['commentCount'] !== '' && $p['commentCount'] !== null) ? (int) $p['commentCount'] : null;
                if ($cm !== null) {
                    $cSum += $cm;
                    $cN++;
                }
                if ($ymd) {
                    $dates[] = $ymd;
                }
                $posts[] = ['t' => mb_substr($title, 0, 80), 'd' => $ymd, 'c' => $cm, 'no' => $p['logNo'] ?? null];
            }
            $out['posts_json'] = json_encode($posts, JSON_UNESCAPED_UNICODE);
            $out['avg_comment'] = $cN ? round($cSum / $cN, 1) : null;
            // 주당 포스팅: p1 글들이 커버한 기간 기준
            if (count($dates) >= 2) {
                $span = max(1, (strtotime($ref) - strtotime(min($dates))) / 86400);
                $out['post_per_week'] = round(count($dates) / ($span / 7), 2);
            } elseif (count($dates) === 1) {
                $out['post_per_week'] = 0.25;
            }
            // ④ 개설(활동 시작) 근사: 마지막 페이지의 가장 오래된 글
            $per = max(1, (int) $p1['countPerPage']);
            $lastPage = max(1, (int) ceil($total / $per));
            if ($lastPage > 1) {
                $h2 = $this->multiGet(['pl' => ['https://blog.naver.com/PostTitleListAsync.naver?blogId='.$bid.'&viewdate=&currentPage='.$lastPage.'&categoryNo=&parentCategoryNo=&countPerPage=30', $listRef]], 1);
                $pl = $this->postsDecode($h2['pl'] ?? '');
                if ($pl && $pl['postList']) {
                    $olds = [];
                    foreach ($pl['postList'] as $p) {
                        $d = $this->parseAddDate((string) $p['addDate'], $ref);
                        if ($d) {
                            $olds[] = $d;
                        }
                    }
                    if ($olds) {
                        $out['since_date'] = min($olds);
                    }
                }
            } elseif ($dates) {
                $out['since_date'] = min($dates);
            }
            $out['ok'] = 1;
        }

        // ⑤ 품질 점수(절대 스케일 0~100): 활동성·반응·방문자·이웃·활동기간
        if ($out['ok']) {
            $sAct = min(1, ($out['post_per_week'] ?? 0) / 5) * 100;
            $sCmt = min(1, ($out['avg_comment'] ?? 0) / 30) * 100;
            $sVis = min(1, ($out['day_visitor_avg'] ?? 0) / 1000) * 100;
            $sSub = min(1, ($out['subscriber_cnt'] ?? 0) / 1000) * 100;
            $years = ! empty($out['since_date']) ? (strtotime($ref) - strtotime($out['since_date'])) / 31536000 : 0;
            $sAge = min(1, $years / 5) * 100;
            $out['score'] = round(0.25 * $sAct + 0.20 * $sCmt + 0.20 * $sVis + 0.25 * $sSub + 0.10 * $sAge, 2);
        }

        return $out;
    }

    /** URL 병렬 GET(referer 개별 지정). @param array<string,array{0:string,1:string}> $reqs */
    private function multiGet(array $reqs, int $conc = 4): array
    {
        $out = [];
        foreach (array_chunk($reqs, max(1, $conc), true) as $ci => $chunk) {
            if ($ci > 0) {
                usleep(200000);
            }
            $mh = curl_multi_init();
            $chs = [];
            foreach ($chunk as $k => $req) {
                $hd = ['accept: */*', 'accept-language: ko-KR,ko;q=0.9', 'user-agent: '.$this->ua];
                if (! empty($req[1])) {
                    $hd[] = 'referer: '.$req[1];
                }
                $ch = curl_init($req[0]);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => 1, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_ENCODING => '', CURLOPT_TIMEOUT => 15,
                    CURLOPT_HTTPHEADER => $hd,
                ]);
                $chs[$k] = $ch;
                curl_multi_add_handle($mh, $ch);
            }
            $running = null;
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh, 1.0);
            } while ($running > 0);
            foreach ($chs as $k => $ch) {
                $out[$k] = (string) curl_multi_getcontent($ch);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
        }

        return $out;
    }

    /** PostTitleListAsync 응답 정리(json 내 \' 불법 이스케이프) 후 decode. */
    private function postsDecode(string $body): ?array
    {
        if ($body === '') {
            return null;
        }
        $j = json_decode(str_replace("\\'", "'", $body), true);

        return (is_array($j) && isset($j['postList'])) ? $j : null;
    }

    /** "2026. 7. 9." | "N분/시간 전" | "어제" → Y-m-d. */
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
