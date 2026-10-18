<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Order\PrepaidWallet;
use App\Http\Controllers\Controller;
use App\Models\Topup;
use Illuminate\Http\Request;

class TopupController extends Controller
{
    public function index()
    {
        return view('admin.topups.index', ['topups' => Topup::with('user')->latest()->paginate(30)]);
    }

    public function review(Request $request, Topup $topup, PrepaidWallet $wallet)
    {
        $data = $request->validate(['status' => ['required', 'in:confirmed,rejected']]);
        $wallet->review($topup, $request->user(), $data['status']);

        return back()->with('status', '충전 요청을 처리했습니다.');
    }
}
