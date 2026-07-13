<?php

namespace App\Http\Controllers;

use App\Models\SellerTalkContact;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 셀러력 수집 중 확보한 판매자 톡톡/스토어 연락처 — 슈퍼어드민 전용 조회(콘솔).
 * 키워드·몰이름·순위·톡톡아이디·수집일. 마케팅 리드 소싱.
 */
class TalkContactController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $q = trim((string) $request->query('q', ''));
        $rows = SellerTalkContact::query()
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q) {
                $w->where('keyword', 'like', "%{$q}%")
                    ->orWhere('mall_name', 'like', "%{$q}%")
                    ->orWhere('talk_id', 'like', "%{$q}%");
            }))
            ->orderByDesc('collected_at')
            ->paginate(50)
            ->withQueryString();

        $total = SellerTalkContact::count();

        return view('console.talk-contacts', compact('rows', 'q', 'total'));
    }

    /** CSV 내보내기(슈퍼어드민 전용). */
    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $q = trim((string) $request->query('q', ''));
        $filename = 'talk-contacts-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM(엑셀 한글)
            fputcsv($out, ['키워드', '몰이름', '순위', '톡톡아이디', '수집일']);
            SellerTalkContact::query()
                ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q) {
                    $w->where('keyword', 'like', "%{$q}%")
                        ->orWhere('mall_name', 'like', "%{$q}%")
                        ->orWhere('talk_id', 'like', "%{$q}%");
                }))
                ->orderByDesc('collected_at')
                ->chunk(500, function ($chunk) use ($out) {
                    foreach ($chunk as $r) {
                        fputcsv($out, [
                            $r->keyword, $r->mall_name, $r->rank, $r->talk_id,
                            optional($r->collected_at)->format('Y-m-d H:i'),
                        ]);
                    }
                });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
