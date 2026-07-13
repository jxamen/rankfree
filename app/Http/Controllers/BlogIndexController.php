<?php

namespace App\Http\Controllers;

use App\Domain\Blog\BlogIndexAnalyzer;
use App\Models\BlogIndexAnalysis;
use App\Models\SavedBlogger;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * 마케팅 > 블로그 지수 분석 — 콘솔(console.blog).
 * 입력이 키워드면 블로그 검색 상위 블로거들을, blogId/URL이면 단건을 분석.
 * 서버 폴백 경로(확장 없이도 동작). 결과는 사용자별 이력으로 저장.
 */
class BlogIndexController extends Controller
{
    public function index(Request $request, BlogIndexAnalyzer $analyzer)
    {
        $user = $request->user();
        $input = trim((string) $request->query('q', ''));

        $type = null;
        if ($input !== '') {
            $type = $this->detectType($input, $analyzer);
            @set_time_limit(300); // 키워드는 블로거 여러 명 × 글 크롤 — 수십 초 소요

            if ($type === 'blog') {
                $r = $analyzer->analyzeBlog($input, 30);
                if ($r) {
                    $current = $this->saveHistory($user->id, 'blog', $r['blog_id'], $r['profile']['blog_name'] ?: $r['blog_id'], $r['score'], $r['grade'], 1, $r);

                    // PRG: 수집 후 이력 URL로 이동 → 새로고침해도 재수집 없이 스냅샷으로 즉시 열림
                    return redirect()->route('console.blog.show', $current);
                }
            } else {
                // 검색 상위 블로거들(첫 페이지) 분석 — 노출된 글 1개씩만, 롤링 병렬
                $r = $analyzer->analyzeKeyword($input, 30, 15);
                if (! empty($r['bloggers'])) {
                    $avg = round(array_sum(array_map(fn ($b) => $b['score'], $r['bloggers'])) / count($r['bloggers']), 1);
                    $current = $this->saveHistory($user->id, 'keyword', $input, $input, $avg, null, count($r['bloggers']), $r);

                    return redirect()->route('console.blog.show', $current);
                }
            }
        }

        // 빈 입력 또는 수집 실패 — 검색폼/이력/에러 안내만 (키워드 분석 이력만)
        return view('console.blog.index', [
            'q' => $input,
            'type' => $type,
            'result' => null,
            'exportable' => null,
            'history' => BlogIndexAnalysis::where('user_id', $user->id)->where('type', 'keyword')->latest('updated_at')->limit(12)->get(),
        ]);
    }

    /**
     * 블로그 1개 분석 — 블로그 ID/URL 전용 단건 분석(키워드 검색 없음).
     * console.blog(겸용)와 로직은 같으나, 입력을 항상 블로그로 취급하고 blog 타입 이력만 보여준다.
     */
    public function single(Request $request, BlogIndexAnalyzer $analyzer)
    {
        $user = $request->user();
        $input = trim((string) $request->query('q', ''));

        if ($input !== '') {
            @set_time_limit(300);
            $r = $analyzer->analyzeBlog($input, 30);
            if ($r) {
                $current = $this->saveHistory($user->id, 'blog', $r['blog_id'], $r['profile']['blog_name'] ?: $r['blog_id'], $r['score'], $r['grade'], 1, $r);

                // PRG: 수집 후 이력 URL로 이동 → 새로고침 시 스냅샷으로 즉시 열림
                return redirect()->route('console.blog.show', $current);
            }
        }

        return view('console.blog.single', [
            'q' => $input,
            'result' => null,
            'exportable' => null,
            'history' => BlogIndexAnalysis::where('user_id', $user->id)->where('type', 'blog')->latest('updated_at')->limit(12)->get(),
        ]);
    }

    /** 이력 상세 — 저장된 스냅샷 재열람. */
    public function show(Request $request, BlogIndexAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        $snap = (array) $analysis->snapshot;
        $result = $analysis->type === 'blog'
            ? ['type' => 'blog', 'blog' => $snap]
            : ['type' => 'keyword', 'keyword' => $snap];

        return view('console.blog.index', [
            'q' => $analysis->query,
            'type' => $analysis->type,
            'result' => $result,
            'exportable' => $analysis,
            // 열람 중인 분석과 같은 타입의 이력만(키워드↔블로그 분리)
            'history' => BlogIndexAnalysis::where('user_id', $request->user()->id)->where('type', $analysis->type)->latest('updated_at')->limit(12)->get(),
            'viewingHistory' => $analysis,
            // 이 키워드로 이미 저장한 블로거 ID들 — 행별 ★ 표시·저장됨 필터용
            'savedIds' => $analysis->type === 'keyword'
                ? SavedBlogger::where('user_id', $request->user()->id)->where('keyword', $analysis->query)->pluck('blog_id')->all()
                : [],
        ]);
    }

    /** 분석 결과 엑셀(XLSX) 다운로드 — 키워드=블로거 목록, 블로그=단건 지표. */
    public function export(Request $request, BlogIndexAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        $snap = (array) $analysis->snapshot;
        $wb = new Spreadsheet();
        $sheet = $wb->getActiveSheet();
        $words = fn ($tw, $n = 8) => implode(', ', array_map(fn ($w) => $w['word'].'('.$w['count'].')', array_slice((array) $tw, 0, $n)));
        $ymd = fn ($d) => ($d && \Illuminate\Support\Carbon::hasFormat((string) $d, 'Ymd')) ? \Illuminate\Support\Carbon::createFromFormat('Ymd', (string) $d)->format('Y-m-d') : (string) $d;

        if ($analysis->type === 'keyword') {
            $sheet->setTitle('블로거');
            $header = ['순위', '블로그명', '블로그ID', '등급', '지수', '이웃수', '일방문', '총글수', '노출글사진', '노출글본문(자)', '전문 주제어', '노출글 제목', '발행일'];
            $sheet->fromArray($header, null, 'A1');
            $row = 2;
            foreach ($snap['bloggers'] ?? [] as $b) {
                $p = $b['profile'] ?? [];
                $q = $b['quality'] ?? [];
                $sheet->fromArray([
                    $b['search_rank'] ?? '', $p['blog_name'] ?? '', $b['blog_id'] ?? '', $b['grade'] ?? '', $b['score'] ?? '',
                    $p['subscriber_cnt'] ?? 0, $p['day_visitor_avg'] ?? 0, $p['post_total'] ?? 0,
                    $q['avg_photos'] ?? 0, $q['avg_length'] ?? 0, $words($q['top_words'] ?? []),
                    $b['featured']['title'] ?? '', $ymd($b['featured']['date'] ?? ''),
                ], null, 'A'.$row++);
            }
            $lastCol = 'M';
        } else {
            $sheet->setTitle('블로그 지수');
            $p = $snap['profile'] ?? [];
            $q = $snap['quality'] ?? [];
            $rows = [
                ['블로그명', $p['blog_name'] ?? ''], ['블로그ID', $snap['blog_id'] ?? ''],
                ['종합지수', $snap['score'] ?? ''], ['등급', $snap['grade'] ?? ''],
                ['이웃수', $p['subscriber_cnt'] ?? 0], ['일평균방문', $p['day_visitor_avg'] ?? 0],
                ['누적방문', $p['total_visitor'] ?? 0], ['총글수', $p['post_total'] ?? 0],
                ['주당포스팅', $p['post_per_week'] ?? 0], ['평균댓글', $p['avg_comment'] ?? 0],
                ['최근발행', $p['last_post'] ?? ''], ['주제집중도(%)', $p['top_focus'] ?? 0],
                ['평균사진', $q['avg_photos'] ?? 0], ['평균본문(자)', $q['avg_length'] ?? 0], ['영상비율(%)', $q['video_ratio'] ?? 0],
                ['전문 주제어', $words($q['top_words'] ?? [], 15)],
            ];
            $sheet->fromArray($rows, null, 'A1');
            $lastCol = 'B';
        }
        $sheet->getStyle('A1:'.$lastCol.'1')->getFont()->setBold(true);
        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'blog_'.$analysis->type.'_'.preg_replace('/[^\w가-힣]+/u', '_', (string) $analysis->query).'_'.now()->format('Ymd_His').'.xlsx';

        return response()->streamDownload(function () use ($wb) {
            (new Xlsx($wb))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * 다음 페이지 수집(AJAX) — blog.json 다음 start 페이지를 추가 분석해 기존 이력에 누적.
     * 이미 수집한 (blogId|logNo)는 건너뛰고, 새 행의 partial HTML을 반환한다.
     */
    public function collectMore(Request $request, BlogIndexAnalysis $analysis, BlogIndexAnalyzer $analyzer)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        abort_unless($analysis->type === 'keyword', 400);
        @set_time_limit(300);

        $start = max(1, (int) $request->input('start', 1));
        $snap = (array) $analysis->snapshot;
        $existing = $snap['bloggers'] ?? [];

        $seen = [];
        $maxRank = 0;
        foreach ($existing as $b) {
            $seen[($b['blog_id'] ?? '').'|'.($b['featured']['log_no'] ?? '')] = true;
            $maxRank = max($maxRank, (int) ($b['search_rank'] ?? 0));
        }

        $more = $analyzer->analyzeKeyword($analysis->query, 30, 15, $start);
        $added = [];
        foreach ($more['bloggers'] ?? [] as $b) {
            $key = ($b['blog_id'] ?? '').'|'.($b['featured']['log_no'] ?? '');
            if ($key === '|' || isset($seen[$key])) {
                continue; // 이미 수집한 글은 건너뜀
            }
            $seen[$key] = true;
            $b['search_rank'] = ++$maxRank;
            $existing[] = $b;
            $added[] = $b;
        }

        $snap['bloggers'] = $existing;
        $snap['next_start'] = $more['next_start'] ?? ($start + 30);
        $avg = count($existing) ? round(array_sum(array_map(fn ($b) => $b['score'], $existing)) / count($existing), 1) : $analysis->score;
        $analysis->update([
            'snapshot' => $snap,
            'blogger_count' => count($existing),
            'score' => $avg,
        ]);

        $savedIds = SavedBlogger::where('user_id', $request->user()->id)->where('keyword', $analysis->query)->pluck('blog_id')->all();
        $rows = '';
        foreach ($added as $b) {
            $rows .= view('console.blog._kw_row', ['b' => $b, 'savedIds' => $savedIds])->render();
        }

        return response()->json([
            'added' => count($added),
            'next_start' => $snap['next_start'],
            'rows' => $rows,
        ]);
    }

    public function destroy(Request $request, BlogIndexAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        $analysis->delete();

        return redirect()->route('console.blog')->with('status', '분석 이력을 삭제했습니다.');
    }

    /**
     * 블로거 저장(AJAX) — 키워드 분석 스냅샷에서 blog_ids를 찾아 (키워드×ID) 조합으로 저장.
     * 단건·다중 공용. 이미 저장된 조합은 최신 스냅샷으로 갱신.
     */
    public function saveBloggers(Request $request, BlogIndexAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        abort_unless($analysis->type === 'keyword', 400);

        $ids = array_values(array_unique(array_filter((array) $request->input('blog_ids', []), 'is_string')));
        abort_unless($ids, 422);

        $snap = (array) $analysis->snapshot;
        $byId = [];
        foreach ($snap['bloggers'] ?? [] as $b) {
            if (! empty($b['blog_id'])) {
                $byId[$b['blog_id']] = $b;
            }
        }

        $saved = 0;
        foreach ($ids as $bid) {
            $b = $byId[$bid] ?? null;
            if (! $b) {
                continue; // 스냅샷에 없는 ID는 무시(조작 방지)
            }
            SavedBlogger::updateOrCreate(
                ['user_id' => $request->user()->id, 'keyword' => $analysis->query, 'blog_id' => $bid],
                [
                    'blog_name' => mb_substr((string) (($b['profile']['blog_name'] ?? '') ?: $bid), 0, 150),
                    'score' => $b['score'] ?? null,
                    'grade' => $b['grade'] ?? null,
                    'data' => $b,
                ],
            );
            $saved++;
        }

        return response()->json(['saved' => $saved]);
    }

    /** 블로거 저장 해제(AJAX) — 분석 화면의 ★ 토글용. */
    public function unsaveBloggers(Request $request, BlogIndexAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        $ids = array_values(array_filter((array) $request->input('blog_ids', []), 'is_string'));
        $removed = $ids
            ? SavedBlogger::where('user_id', $request->user()->id)->where('keyword', $analysis->query)->whereIn('blog_id', $ids)->delete()
            : 0;

        return response()->json(['removed' => $removed]);
    }

    /** 저장 블로거 목록 — 키워드 필터로 모아보기, 엑셀·삭제 진입점. */
    public function saved(Request $request)
    {
        $user = $request->user();
        $kw = trim((string) $request->query('kw', ''));

        // 키워드별 저장 건수(필터 칩) + 필터 적용 목록
        $keywords = SavedBlogger::where('user_id', $user->id)
            ->selectRaw('keyword, count(*) as cnt')->groupBy('keyword')->orderBy('keyword')->get();
        $rows = SavedBlogger::where('user_id', $user->id)
            ->when($kw !== '', fn ($q) => $q->where('keyword', $kw))
            ->latest('id')->get();

        return view('console.blog.saved', ['rows' => $rows, 'keywords' => $keywords, 'kw' => $kw]);
    }

    /** 저장 블로거 엑셀(XLSX) — 현재 키워드 필터 반영. */
    public function savedExport(Request $request)
    {
        $kw = trim((string) $request->query('kw', ''));
        $rows = SavedBlogger::where('user_id', $request->user()->id)
            ->when($kw !== '', fn ($q) => $q->where('keyword', $kw))
            ->latest('id')->get();

        $wb = new Spreadsheet();
        $sheet = $wb->getActiveSheet();
        $sheet->setTitle('저장 블로거');
        $words = fn ($tw, $n = 8) => implode(', ', array_map(fn ($w) => $w['word'].'('.$w['count'].')', array_slice((array) $tw, 0, $n)));
        $ymd = fn ($d) => ($d && \Illuminate\Support\Carbon::hasFormat((string) $d, 'Ymd')) ? \Illuminate\Support\Carbon::createFromFormat('Ymd', (string) $d)->format('Y-m-d') : (string) $d;

        $header = ['키워드', '블로그명', '블로그ID', '등급', '지수', '이웃수', '일방문', '총글수', '노출글사진', '노출글본문(자)', '전문 주제어', '노출글 제목', '발행일', '저장일'];
        $sheet->fromArray($header, null, 'A1');
        $row = 2;
        foreach ($rows as $s) {
            $b = (array) $s->data;
            $p = $b['profile'] ?? [];
            $q = $b['quality'] ?? [];
            $sheet->fromArray([
                $s->keyword, $s->blog_name, $s->blog_id, $s->grade, $s->score,
                $p['subscriber_cnt'] ?? 0, $p['day_visitor_avg'] ?? 0, $p['post_total'] ?? 0,
                $q['avg_photos'] ?? 0, $q['avg_length'] ?? 0, $words($q['top_words'] ?? []),
                $b['featured']['title'] ?? '', $ymd($b['featured']['date'] ?? ''),
                $s->created_at?->format('Y-m-d H:i'),
            ], null, 'A'.$row++);
        }
        $sheet->getStyle('A1:N1')->getFont()->setBold(true);
        foreach (range('A', 'N') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $suffix = $kw !== '' ? preg_replace('/[^\w가-힣]+/u', '_', $kw) : '전체';
        $filename = 'saved_bloggers_'.$suffix.'_'.now()->format('Ymd_His').'.xlsx';

        return response()->streamDownload(function () use ($wb) {
            (new Xlsx($wb))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** 저장 블로거 삭제 — 단건·다중(ids[]) 공용. */
    public function savedDestroy(Request $request)
    {
        $ids = array_values(array_filter((array) $request->input('ids', []), 'is_numeric'));
        $removed = $ids
            ? SavedBlogger::where('user_id', $request->user()->id)->whereIn('id', $ids)->delete()
            : 0;

        if ($request->expectsJson()) {
            return response()->json(['removed' => $removed]);
        }

        return back()->with('status', $removed.'개 저장 블로거를 삭제했습니다.');
    }

    /** 입력이 블로그ID/URL인지 키워드인지 판별. */
    private function detectType(string $input, BlogIndexAnalyzer $analyzer): string
    {
        // 네이버 블로그 URL 이거나, 공백·한글 없는 영숫자 ID면 blog
        if (preg_match('#blog\.naver\.com#i', $input)) {
            return 'blog';
        }
        if (! preg_match('/\s/', $input) && preg_match('/^[A-Za-z0-9_\-]{2,40}$/', $input)) {
            return 'blog';
        }

        return 'keyword';
    }

    private function saveHistory(int $userId, string $type, string $query, string $title, ?float $score, ?string $grade, int $bloggerCount, array $snapshot): BlogIndexAnalysis
    {
        return BlogIndexAnalysis::updateOrCreate(
            ['user_id' => $userId, 'type' => $type, 'query' => $query],
            [
                'title' => mb_substr($title, 0, 150),
                'score' => $score,
                'grade' => $grade,
                'blogger_count' => $bloggerCount,
                'snapshot' => $snapshot,
            ],
        );
    }
}
