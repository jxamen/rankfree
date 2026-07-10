<?php

namespace App\Http\Controllers;

use App\Domain\Place\RankSlotService;
use App\Models\PlaceRankSlot;
use DomainException;
use Illuminate\Http\Request;

/** 순위 추적 슬롯 — 웹 콘솔 (등록/조회/삭제/즉시갱신). 로직은 RankSlotService 공유. */
class RankTrackController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return view('console.rank', [
            'slots' => $user->rankSlots()->with('records')->latest()->get(),
            'usedSlots' => $user->rankSlotsUsed(),
            'maxSlots' => $user->rankSlotLimit(),
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

        $n = count($res['created']);
        $name = $res['place']['place_name'] ?: ($res['place']['place_id'] ? 'ID '.$res['place']['place_id'] : $data['place']);
        $msg = $n > 0
            ? "‘{$name}’ · 키워드 {$n}개 추적 추가됨. \"지금 확인\"으로 순위를 갱신하세요."
            : '추가된 키워드가 없습니다.';
        if (count($res['skipped'])) {
            $msg .= ' (중복 제외: '.implode(', ', $res['skipped']).')';
        }

        return back()->with('status', $msg);
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
        $r = $service->run($slot);

        $msg = $r['blocked']
            ? '조회가 일시적으로 제한됐습니다 (nCaptcha 토큰 재발급 필요).'
            : ($r['found'] ? $slot->keyword.' 순위 '.$r['rank'].'위' : '300위 밖입니다.');

        return back()->with('status', $msg);
    }

    public function destroy(Request $request, PlaceRankSlot $slot)
    {
        abort_unless($slot->user_id === $request->user()->id, 403);
        $slot->delete();

        return back()->with('status', '추적 슬롯을 삭제했습니다.');
    }
}
