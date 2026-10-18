<?php

namespace App\Http\Controllers;

use App\Models\Topup;
use App\Support\BankAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletController extends Controller
{
    public function index(Request $request)
    {
        return view('wallet.index', ['balance' => (int) $request->user()->fresh()->prepaid_balance, 'bank' => BankAccount::info(), 'topups' => Topup::where('user_id', $request->user()->id)->latest()->paginate(15, ['*'], 'topups'), 'entries' => DB::table('wallet_entries')->where('user_id', $request->user()->id)->latest('id')->paginate(20)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1', 'max:100000000'], 'depositor' => ['required', 'string', 'max:100']]);
        if (! BankAccount::isConfigured()) {
            return back()->withErrors(['amount' => '입금 계좌가 준비되지 않았습니다. 관리자에게 문의하세요.']);
        }
        Topup::create($data + ['user_id' => $request->user()->id, 'code' => 'RF'.strtoupper(Str::random(10))]);

        return back()->with('status', '충전을 요청했습니다. 아래 계좌에 요청 금액을 입금하고 입금자명 또는 요청 코드를 알려 주세요.');
    }
}
