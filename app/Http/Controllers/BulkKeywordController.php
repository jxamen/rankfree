<?php

namespace App\Http\Controllers;

use App\Domain\Keyword\BulkKeywordCollector;
use App\Domain\Keyword\BulkKeywordExporter;
use App\Models\BulkKeyword;
use App\Models\BulkKeywordItem;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * 키워드 대량 분석 — 콘솔(console.bulk).
 * 텍스트/엑셀로 키워드 업로드 → 브라우저 폴링 청크 수집(AJAX) → 엑셀 다운로드.
 * 큐 워커 없이 동작하도록 process()가 청크 단위로 pending 항목을 수집한다.
 */
class BulkKeywordController extends Controller
{
    private const MAX_KEYWORDS = 500;

    public function index(Request $request)
    {
        return view('console.bulk.index', [
            'batches' => $request->user()->bulkKeywords()->latest()->limit(30)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'keywords' => ['nullable', 'string'],
            'file' => ['nullable', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120'],
            'include_serp' => ['nullable'],
        ]);

        $keywords = $this->parseKeywords((string) $request->input('keywords'));
        if ($request->hasFile('file')) {
            $keywords = array_merge($keywords, $this->parseFile($request->file('file')->getRealPath()));
        }

        // 정규화·중복 제거·상한
        $seen = [];
        $clean = [];
        foreach ($keywords as $kw) {
            $kw = trim(preg_replace('/\s+/u', ' ', $kw));
            $norm = mb_strtolower(str_replace(' ', '', $kw));
            if ($kw === '' || mb_strlen($kw) > 60 || isset($seen[$norm])) {
                continue;
            }
            $seen[$norm] = true;
            $clean[] = $kw;
            if (count($clean) >= self::MAX_KEYWORDS) {
                break;
            }
        }

        if (! $clean) {
            return back()->with('status', '분석할 키워드가 없습니다. 키워드를 입력하거나 파일을 업로드하세요.');
        }

        $batch = $request->user()->bulkKeywords()->create([
            'name' => $clean[0].(count($clean) > 1 ? ' 외 '.(count($clean) - 1).'개' : ''),
            'status' => 'processing',
            'total' => count($clean),
            'include_serp' => (bool) $request->boolean('include_serp'),
        ]);
        foreach ($clean as $i => $kw) {
            $batch->items()->create(['keyword' => $kw, 'sort' => $i]);
        }

        return redirect()->route('console.bulk.show', $batch);
    }

    public function show(Request $request, BulkKeyword $bulk)
    {
        abort_unless($bulk->user_id === $request->user()->id, 403);

        return view('console.bulk.show', ['bulk' => $bulk->load(['items' => fn ($q) => $q->orderBy('sort')])]);
    }

    /** 청크 수집(AJAX) — pending 항목 몇 개를 수집하고 진행률 반환. */
    public function process(Request $request, BulkKeyword $bulk, BulkKeywordCollector $collector)
    {
        abort_unless($bulk->user_id === $request->user()->id, 403);

        @set_time_limit(120);
        // SERP 포함이면 무거워 청크 작게
        $chunk = $bulk->include_serp ? 2 : 5;
        $items = $bulk->items()->where('status', 'pending')->orderBy('sort')->limit($chunk)->get();

        foreach ($items as $item) {
            $res = $collector->collect($item->keyword, $bulk->include_serp);
            if ($res['ok']) {
                $item->update(['status' => 'done', 'data' => $res['data'], 'fail_reason' => null]);
                $bulk->increment('done');
            } else {
                $item->update(['status' => 'failed', 'fail_reason' => $res['reason']]);
                $bulk->increment('failed');
            }
        }

        $bulk->refresh();
        $remaining = $bulk->items()->where('status', 'pending')->count();
        if ($remaining === 0 && $bulk->status !== 'done') {
            $bulk->update(['status' => 'done', 'finished_at' => now()]);
        }

        return response()->json([
            'done' => $bulk->done,
            'failed' => $bulk->failed,
            'total' => $bulk->total,
            'pct' => $bulk->progressPct(),
            'finished' => $remaining === 0,
        ]);
    }

    public function export(Request $request, BulkKeyword $bulk, BulkKeywordExporter $exporter)
    {
        abort_unless($bulk->user_id === $request->user()->id, 403);

        return $exporter->download($bulk->load(['items' => fn ($q) => $q->orderBy('sort')]));
    }

    public function destroy(Request $request, BulkKeyword $bulk)
    {
        abort_unless($bulk->user_id === $request->user()->id, 403);
        $bulk->delete();

        return redirect()->route('console.bulk')->with('status', '대량 분석 내역을 삭제했습니다.');
    }

    /** 텍스트 → 키워드 목록(줄바꿈·쉼표·탭 구분). */
    private function parseKeywords(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        return preg_split('/[\r\n,\t]+/u', $text) ?: [];
    }

    /** 업로드 파일(xlsx/csv) 첫 열 → 키워드 목록(헤더 자동 스킵). */
    private function parseFile(string $path): array
    {
        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
            $out = [];
            foreach ($sheet->toArray(null, true, false, false) as $ri => $row) {
                $first = trim((string) ($row[0] ?? ''));
                $second = isset($row[1]) ? trim((string) $row[1]) : '';
                // 1행이 헤더(키워드/No 등)면 스킵
                if ($ri === 0 && (in_array($first, ['키워드', 'keyword', 'No', 'no'], true) || $second === '키워드')) {
                    continue;
                }
                // 'No | 키워드' 포맷이면 2번째 열이 키워드
                $kw = ($first !== '' && is_numeric($first) && $second !== '') ? $second : $first;
                if ($kw !== '') {
                    $out[] = $kw;
                }
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }
}
