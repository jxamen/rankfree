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
            'usedSlots' => $user->rankSlotsUsed(),
            'maxSlots' => $user->rankSlotLimit(),
            'monthCount' => $monthCount,
            'recent' => $recent,
        ]);
    }
}
