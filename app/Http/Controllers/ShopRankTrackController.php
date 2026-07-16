<?php

namespace App\Http\Controllers;

use App\Domain\Shopping\ShopRankSlotService;
use App\Models\ShopRankSlot;
use DomainException;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** 쇼핑 순위추적 — 콘솔. Place\RankTrackController 미러. */
class ShopRankTrackController extends Controller
{
    public function __construct(private ShopRankSlotService $service) {}

    public function index(Request $request)
    {
        $user = $request->user();

        // 키워드·기간 검색 — 기간 지정 시 해당 기간의 순위 기록만 표시
        $q = trim((string) $request->query('q', ''));
        $from = $request->query('from');
        $to = $request->query('to');
        $from = ($from && strtotime((string) $from)) ? date('Y-m-d', strtotime((string) $from)) : null;
        $to = ($to && strtotime((string) $to)) ? date('Y-m-d', strtotime((string) $to)) : null;

        return view('console.shop-rank', [
            'slots' => $user->shopRankSlots()
                ->when($q !== '', fn ($qq) => $qq->where('keyword', 'like', '%'.$q.'%'))
                ->with('records')->latest()->get(),
            'usedSlots' => $user->rankSlotsUsedTotal(),
            'maxSlots' => $user->rankSlotLimit(),
            'q' => $q,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /** 추적 데이터 엑셀(XLSX) 다운로드 — 키워드별 시트, 열: 날짜·순위·가격·노출수. Place\RankTrackController::export 미러. */
    public function export(Request $request)
    {
        $slots = $request->user()->shopRankSlots()->with('records')->latest()->get();

        $wb = new Spreadsheet();
        $wb->removeSheetByIndex(0);
        $titles = [];

        foreach ($slots as $slot) {
            // 시트명 = 키워드 (Excel 금지문자 제거·31자 제한·중복 시 번호)
            $base = mb_substr(trim(preg_replace('#[\\\\/*?:\[\]]#u', ' ', $slot->keyword)) ?: '키워드', 0, 28);
            $title = $base;
            for ($n = 2; in_array($title, $titles, true); $n++) {
                $title = $base.' ('.$n.')';
            }
            $titles[] = $title;

            $sheet = $wb->createSheet();
            $sheet->setTitle($title);

            $header = ['날짜', '순위', '가격', '노출 총개수'];
            $lastCol = chr(ord('A') + count($header) - 1);
            $sheet->fromArray($header, null, 'A1');
            $sheet->getStyle('A1:'.$lastCol.'1')->getFont()->setBold(true);

            $row = 2;
            foreach ($slot->records->sortByDesc('checked_date') as $rec) {
                $rank = $rec->rank > 0 ? $rec->rank : ($rec->rank < 0 ? '차단' : '순위권 밖');
                $sheet->fromArray([
                    $rec->checked_date->format('Y-m-d'),
                    $rank,
                    $rec->price ?: '',
                    $rec->list_total ?: '',
                ], null, 'A'.$row++);
            }
            foreach (range('A', $lastCol) as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
        }

        if ($wb->getSheetCount() === 0) {
            $wb->createSheet()->setTitle('데이터 없음');
        }
        $wb->setActiveSheetIndex(0);

        $filename = 'rankfree_shoprank_'.now()->format('Ymd_His').'.xlsx';

        return response()->streamDownload(function () use ($wb) {
            (new Xlsx($wb))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** 대상 미리보기(AJAX) — 상품 URL/업체명 파싱. */
    public function resolve(Request $request)
    {
        $input = trim((string) $request->query('target', ''));
        if ($input === '') {
            return response()->json(['ok' => false, 'message' => 'target 이 비었습니다.'], 422);
        }
        $t = $this->service->resolve($input);
        $label = $t['product_id'] !== '' ? '상품 ID '.$t['product_id'] : '업체명 '.$t['mall_name'];

        return response()->json(['ok' => $t['product_id'] !== '' || $t['mall_name'] !== ''] + $t + ['label' => $label]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'target' => 'required|string|max:500',
            'keywords' => 'required|array|min:1',
            'keywords.*' => 'nullable|string|max:120',
            'label' => 'nullable|string|max:100',
        ]);

        try {
            $res = $this->service->addMany($request->user(), $data['target'], $data['keywords'], $data['label'] ?? null);
        } catch (DomainException $e) {
            return back()->withErrors(['target' => $e->getMessage()])->withInput();
        }

        // 생성 직후 첫 순위 1회 실행(실패 허용)
        foreach ($res['created'] as $slot) {
            try {
                $this->service->run($slot);
            } catch (\Throwable) {
                // 무시 — 목록에서 수동 재실행 가능
            }
        }

        $created = count($res['created']);
        $skipped = count($res['skipped']);
        $msg = $created > 0 ? "{$created}개 추적을 추가했습니다." : '추가된 추적이 없습니다.';
        if ($skipped > 0) {
            $msg .= " (중복 {$skipped}개 제외: ".implode(', ', $res['skipped']).')';
        }

        return redirect()->route('console.shop-rank')->with('status', $msg);
    }

    public function update(Request $request, ShopRankSlot $slot)
    {
        abort_unless($slot->user_id === $request->user()->id, 403);
        $data = $request->validate([
            'keyword' => 'required|string|max:120',
            'target' => 'required|string|max:500',
            'label' => 'nullable|string|max:100',
        ]);

        $t = $this->service->resolve($data['target']);
        if ($t['product_id'] === '' && $t['mall_name'] === '') {
            return back()->withErrors(['target' => '상품 URL 또는 업체명을 확인하세요.'])->withInput();
        }

        // 같은 대상 + 같은 키워드 중복 방지(자기 자신 제외)
        $dup = ShopRankSlot::where('user_id', $request->user()->id)->where('keyword', $data['keyword'])
            ->where('id', '!=', $slot->id)
            ->where(fn ($q) => $t['product_id'] !== '' ? $q->where('product_id', $t['product_id']) : $q->where('mall_name', $t['mall_name']))
            ->exists();
        if ($dup) {
            return back()->withErrors(['keyword' => '이미 추적 중인 키워드입니다.'])->withInput();
        }

        $slot->update([
            'keyword' => $data['keyword'],
            'label' => $data['label'] ?: null,
            'target_type' => $t['type'],
            'product_id' => $t['product_id'] ?: null,
            'mall_name' => $t['mall_name'] ?: null,
            'product_url' => $t['url'] ?: null,
        ]);

        return redirect()->route('console.shop-rank')->with('status', '추적을 수정했습니다.');
    }

    public function run(Request $request, ShopRankSlot $slot)
    {
        abort_unless($slot->user_id === $request->user()->id, 403);

        // 동일 키워드 1시간 이내 재체크 제한 — 수동 실행 남용 방지(자동 수집 크론은 커맨드 경유라 영향 없음)
        if ($slot->last_checked_at && $slot->last_checked_at->gt(now()->subHour())) {
            $msg = '같은 키워드는 1시간에 한 번만 확인할 수 있습니다. 다음 가능 시각 '
                .$slot->last_checked_at->copy()->addHour()->timezone('Asia/Seoul')->format('H:i');
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'found' => false, 'blocked' => false, 'rank' => 0, 'message' => $msg]);
            }

            return back()->withErrors(['run' => $msg]);
        }

        $res = $this->service->run($slot);

        $max = (int) config('rankfree.shopping.display', 100) * (int) config('rankfree.shopping.max_pages', 10);
        $msg = $res['found']
            ? "{$res['rank']}위"
            : ($res['blocked'] ? 'API 한도로 조회가 지연됩니다. 잠시 후 재시도하세요.' : "{$max}위 밖");

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => ! isset($res['error']),
                'found' => (bool) $res['found'],
                'blocked' => (bool) $res['blocked'],
                'rank' => (int) $res['rank'],
                'message' => $msg,
            ]);
        }

        return back()->with('status', "「{$slot->keyword}」 {$msg}");
    }

    public function destroy(Request $request, ShopRankSlot $slot)
    {
        abort_unless($slot->user_id === $request->user()->id, 403);
        $slot->delete();

        return redirect()->route('console.shop-rank')->with('status', '추적을 삭제했습니다.');
    }

    /** 공개 공유 리포트(비로그인). */
    public function shared(string $token)
    {
        $slot = ShopRankSlot::where('share_token', $token)->with('records')->firstOrFail();

        return view('shop-rank.share', ['slot' => $slot]);
    }
}
