<?php

namespace App\Http\Controllers;

use App\Domain\Place\RankSlotService;
use App\Models\PlaceRankSlot;
use DomainException;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** 순위 추적 슬롯 — 웹 콘솔 (등록/조회/삭제/즉시갱신). 로직은 RankSlotService 공유. */
class RankTrackController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // 키워드·기간 검색 — 기간 지정 시 해당 기간의 순위 기록만 표시
        $q = trim((string) $request->query('q', ''));
        $from = $request->query('from');
        $to = $request->query('to');
        $from = ($from && strtotime((string) $from)) ? date('Y-m-d', strtotime((string) $from)) : null;
        $to = ($to && strtotime((string) $to)) ? date('Y-m-d', strtotime((string) $to)) : null;

        return view('console.rank', [
            'slots' => $user->rankSlots()
                ->when($q !== '', fn ($qq) => $qq->where('keyword', 'like', '%'.$q.'%'))
                ->with('records')->latest()->get(),
            'usedSlots' => $user->rankSlotsUsedTotal(),
            'maxSlots' => $user->rankSlotLimit(),
            'q' => $q,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /** URL/ID 1개 + 키워드 N개 → 슬롯 N개. 업체명 자동조회. */
    public function store(Request $request, RankSlotService $service)
    {
        $data = $request->validate([
            'place' => ['required', 'string', 'max:300'],
            'keywords' => ['required', 'array', 'min:1'],
            'keywords.*' => ['nullable', 'string', 'max:100'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $res = $service->addMany($request->user(), $data['place'], $data['keywords'], $data['label'] ?? null);
        } catch (DomainException $e) {
            return back()->withErrors(['place' => $e->getMessage()])->withInput();
        }

        // 등록 즉시 첫 순위 수집 — 실패해도 등록은 유지된다.
        // 키워드가 많으면 20초 예산까지만 확인(게이트웨이 타임아웃 방지) — 나머지는 일일 자동 수집이 처리.
        $firsts = [];
        $t0 = microtime(true);
        foreach ($res['created'] as $slot) {
            if (microtime(true) - $t0 > 20) {
                $firsts[] = $slot->keyword.' 자동 수집 대기';

                continue;
            }
            try {
                $r = $service->run($slot);
                $firsts[] = $slot->keyword.' '.($r['blocked'] ? '차단' : ($r['found'] ? $r['rank'].'위' : '300+'));
            } catch (\Throwable) {
                $firsts[] = $slot->keyword.' 확인 실패';
            }
        }

        $n = count($res['created']);
        $name = $res['place']['place_name'] ?: ($res['place']['place_id'] ? 'ID '.$res['place']['place_id'] : $data['place']);
        $msg = $n > 0 ? "‘{$name}’ · 키워드 {$n}개 추적 추가됨." : '추가된 키워드가 없습니다.';
        if (count($firsts)) {
            $msg .= ' 첫 순위 — '.implode(' · ', $firsts);
        }
        if (count($res['skipped'])) {
            $msg .= ' (중복 제외: '.implode(', ', $res['skipped']).')';
        }

        return back()->with('status', $msg);
    }

    /** 슬롯 수정 — 키워드·플레이스(URL/ID)·라벨. 같은 플레이스 내 중복 키워드는 거부. */
    public function update(Request $request, PlaceRankSlot $slot, RankSlotService $service)
    {
        abort_unless($slot->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:100'],
            'place' => ['required', 'string', 'max:300'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);
        $kw = trim($data['keyword']);
        $placeInput = trim($data['place']);

        // 입력이 기존 URL/ID 그대로면 재조회 생략(네트워크 절약), 바뀌었으면 등록과 동일하게 재확정
        $placeChanged = $placeInput !== (string) $slot->place_url && $placeInput !== (string) $slot->place_id;
        $place = $placeChanged ? $service->resolvePlace($placeInput) : null;

        $newPlaceId = $placeChanged ? $place['place_id'] : $slot->place_id;
        $newPlaceName = $placeChanged ? $place['place_name'] : $slot->place_name;

        $dupe = $request->user()->rankSlots()
            ->where('id', '!=', $slot->id)
            ->where('keyword', $kw)
            ->when(
                $newPlaceId,
                fn ($q) => $q->where('place_id', $newPlaceId),
                fn ($q) => $q->where('place_name', $newPlaceName),
            )
            ->exists();
        if ($dupe) {
            return back()->withErrors(['keyword' => "'{$kw}' 는 이미 같은 플레이스에서 추적 중인 키워드입니다."])->withInput();
        }

        $changed = $kw !== $slot->keyword || $placeChanged;
        $slot->update(array_merge(
            [
                'keyword' => $kw,
                'label' => $data['label'] !== null && trim($data['label']) !== '' ? trim($data['label']) : null,
            ],
            $placeChanged ? [
                'place_id' => $place['place_id'],
                'place_name' => $place['place_name'],
                'place_url' => $place['place_url'],
                'category' => $place['category'] ?: 'place',
            ] : [],
        ));

        return back()->with('status', $changed
            ? '수정했습니다. 다음 확인부터 변경된 키워드·플레이스 기준으로 기록됩니다.'
            : '수정했습니다.');
    }

    /** 추적 데이터 엑셀(XLSX) 다운로드 — 키워드별 시트, 열: 날짜·순위·영수증·블로그(·저장=음식점만). */
    public function export(Request $request)
    {
        $slots = $request->user()->rankSlots()->with('records')->latest()->get();

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

            $isRestaurant = $slot->category === 'restaurant';
            $header = ['날짜', '순위', '영수증 리뷰', '블로그 리뷰'];
            if ($isRestaurant) {
                $header[] = '저장수';
            }
            $lastCol = chr(ord('A') + count($header) - 1);
            $sheet->fromArray($header, null, 'A1');
            $sheet->getStyle('A1:'.$lastCol.'1')->getFont()->setBold(true);

            $row = 2;
            foreach ($slot->records->sortByDesc('checked_date') as $rec) {
                $rank = $rec->rank > 0 && $rec->rank < 300 ? $rec->rank : ($rec->rank < 0 ? '차단' : '300+');
                $vals = [$rec->checked_date->format('Y-m-d'), $rank, $rec->review_count, $rec->blog_review_count];
                if ($isRestaurant) {
                    $vals[] = $rec->save_count;
                }
                $sheet->fromArray($vals, null, 'A'.$row++);
            }
            foreach (range('A', $lastCol) as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
        }

        if ($wb->getSheetCount() === 0) {
            $wb->createSheet()->setTitle('데이터 없음');
        }
        $wb->setActiveSheetIndex(0);

        $filename = 'rankfree_rank_'.now()->format('Ymd_His').'.xlsx';

        return response()->streamDownload(function () use ($wb) {
            (new Xlsx($wb))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** 업체명 미리보기(AJAX) — URL/ID 입력 시 업체명·카테고리 자동조회. */
    public function resolve(Request $request, RankSlotService $service)
    {
        $input = trim((string) $request->query('place', ''));
        if ($input === '') {
            return response()->json(['ok' => false, 'message' => '플레이스 URL 또는 ID 를 입력하세요.'], 422);
        }

        $p = $service->resolvePlace($input);

        return response()->json([
            'ok' => (bool) ($p['place_id'] || $p['place_name']),
            'place_id' => $p['place_id'],
            'place_name' => $p['place_name'],
            'category' => $p['category'],
            'place_url' => $p['place_url'],
        ]);
    }

    public function run(Request $request, PlaceRankSlot $slot, RankSlotService $service)
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

        $r = $service->run($slot);

        $msg = $r['blocked']
            ? '조회가 일시적으로 제한됐습니다 (nCaptcha 토큰 재발급 필요).'
            : ($r['found'] ? $slot->keyword.' 순위 '.$r['rank'].'위' : '300위 밖입니다.');

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => ! $r['blocked'],
                'found' => $r['found'],
                'blocked' => $r['blocked'],
                'rank' => $r['rank'],
                'message' => $msg,
            ]);
        }

        return back()->with('status', $msg);
    }

    /** 공개 리포트 — SEO 슬러그(또는 구 토큰)로 비로그인 열람(읽기 전용). */
    public function shared(string $slug)
    {
        $slot = PlaceRankSlot::findByShareKey($slug);
        abort_if(! $slot, 404);
        $slot->load('records');

        return view('rank.share', ['slot' => $slot]);
    }

    public function destroy(Request $request, PlaceRankSlot $slot)
    {
        abort_unless($slot->user_id === $request->user()->id, 403);
        $slot->delete();

        return back()->with('status', '추적 슬롯을 삭제했습니다.');
    }
}
