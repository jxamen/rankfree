<?php

namespace App\Http\Controllers;

use App\Models\PlaceRankLookup;
use Illuminate\Http\Request;

class ConsoleController extends Controller
{
    public function dashboard(Request $request)
    {
        $user = $request->user();
        $now = now();

        $monthCount = PlaceRankLookup::where('user_id', $user->id)
            ->whereYear('created_at', $now->year)
            ->whereMonth('created_at', $now->month)
            ->count();

        $recent = PlaceRankLookup::where('user_id', $user->id)
            ->latest()
            ->limit(8)
            ->get();

        return view('console.dashboard', [
            // NOTE: 추적 슬롯 테이블은 다음 단계 — 현재는 정책 상수만 노출
            'usedSlots' => 0,
            'maxSlots' => 100,
            'monthCount' => $monthCount,
            'recent' => $recent,
        ]);
    }
}
