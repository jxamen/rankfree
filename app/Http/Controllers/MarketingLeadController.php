<?php

namespace App\Http\Controllers;

use App\Models\MarketingLead;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 마케팅 리드(상담 문의) 접수·조회.
 * - store: 분석 리포트("순위 상승 문의하기" 등)에서 접수. 비로그인(공개 공유)도 허용.
 * - adminIndex/updateStatus/export: 슈퍼어드민 전용(판매자 톡톡 연락처와 동일한 리드 관리 UX).
 */
class MarketingLeadController extends Controller
{
    /** 리드 접수 — 공개(비로그인 가능). 라우트에서 throttle 적용. */
    public function store(Request $request)
    {
        // 봇 허니팟: 사람에겐 숨겨진 필드가 채워지면 조용히 성공 처리(DB 미기록).
        if (trim((string) $request->input('company', '')) !== '') {
            return $this->ok($request);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'max:40', 'regex:/^[0-9+\-\s().]{7,40}$/'],
            'keyword' => ['nullable', 'string', 'max:160'],
            'source' => ['nullable', 'string', 'max:40'],
            'interest' => ['nullable', 'string', 'max:80'],
            'message' => ['nullable', 'string', 'max:1000'],
        ], [
            'name.required' => '성함을 입력하세요.',
            'phone.required' => '연락처를 입력하세요.',
            'phone.regex' => '연락처 형식을 확인하세요.',
        ]);

        $source = in_array($data['source'] ?? '', array_keys(MarketingLead::SOURCES), true) ? $data['source'] : 'other';

        MarketingLead::create([
            'user_id' => $request->user()?->id,
            'name' => trim($data['name']),
            'phone' => trim($data['phone']),
            'keyword' => $data['keyword'] ?? null,
            'source' => $source,
            'interest' => $data['interest'] ?? null,
            'message' => $data['message'] ?? null,
            'meta' => [
                'peak_months' => $this->intList($request->input('peak_months')),
                'prep_months' => $this->intList($request->input('prep_months')),
                'strength' => $request->filled('strength') ? mb_substr((string) $request->input('strength'), 0, 20) : null,
                'is_public' => ! $request->user(),
            ],
            'status' => 'new',
            'ip' => $request->ip(),
        ]);

        return $this->ok($request);
    }

    private function ok(Request $request)
    {
        $msg = '문의가 접수되었습니다. 담당자가 빠르게 연락드리겠습니다.';
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'message' => $msg]);
        }

        return back()->with('status', $msg);
    }

    /** "1,2,3" 또는 [1,2] 형태 입력 → 1~12 범위 정수 배열. */
    private function intList(mixed $input): array
    {
        $raw = is_array($input) ? $input : preg_split('/[^0-9]+/', (string) $input);

        return array_values(array_filter(array_map('intval', (array) $raw), fn ($v) => $v >= 1 && $v <= 12));
    }

    // ── 슈퍼어드민 관리 ──────────────────────────────────────────────

    public function adminIndex(Request $request)
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $rows = MarketingLead::query()
            ->with('user')
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('keyword', 'like', "%{$q}%")
                    ->orWhere('interest', 'like', "%{$q}%");
            }))
            ->when(array_key_exists($status, MarketingLead::STATUSES), fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        $counts = MarketingLead::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return view('console.leads', compact('rows', 'q', 'status', 'counts'));
    }

    public function updateStatus(Request $request, MarketingLead $lead)
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);
        $data = $request->validate(['status' => ['required', 'in:'.implode(',', array_keys(MarketingLead::STATUSES))]]);
        $lead->update(['status' => $data['status']]);

        return back()->with('status', "'{$lead->name}' 리드 상태를 '{$lead->statusLabel()}'(으)로 변경했습니다.");
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $filename = 'marketing-leads-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM(엑셀 한글)
            fputcsv($out, ['접수일', '상태', '유입', '성함', '연락처', '키워드', '관심', '메시지']);
            MarketingLead::query()->latest()->chunk(500, function ($chunk) use ($out) {
                foreach ($chunk as $l) {
                    fputcsv($out, [
                        optional($l->created_at)->format('Y-m-d H:i'),
                        $l->statusLabel(), $l->sourceLabel(),
                        $l->name, $l->phone, $l->keyword, $l->interest, $l->message,
                    ]);
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
