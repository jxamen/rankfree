<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\MemberGrade;
use App\Models\Notice;
use App\Models\PlaceRankLookup;
use App\Models\Popup;
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
            ->limit(6)
            ->get();

        // 기능별 월 사용량 (구독 등급 기준)
        $features = [];
        foreach (MemberGrade::FEATURES as $key => $label) {
            $limit = $user->featureLimit($key);
            $features[] = [
                'label' => $label,
                'used' => $user->featureUsed($key),
                'limit' => $limit,                       // -1 무제한, 0 미제공, N 월 N회
                'remaining' => $user->featureRemaining($key),
            ];
        }

        return view('console.dashboard', [
            'usedSlots' => $user->rankSlotsUsedTotal(),
            'maxSlots' => $user->rankSlotLimit(),
            'monthCount' => $monthCount,
            'recent' => $recent,
            'features' => $features,
            'notices' => Notice::visible()->listed()->limit(5)->get(),
            'banners' => Banner::activeNow()->sorted()->get(),
            'popups' => Popup::activeNow()->sorted()->get(),
        ]);
    }
}
