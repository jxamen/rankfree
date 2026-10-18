<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Order\OrderInputException;
use App\Domain\Order\PrepaidWallet;
use App\Domain\Order\TopupAdmin;
use App\Http\Controllers\Controller;
use App\Models\Topup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TopupController extends Controller
{
    public function index(Request $request)
    {
        $d = $request->validate(['q' => 'nullable|string|max:160', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'status' => 'nullable|in:pending,confirmed,rejected']);
        $q = Topup::with('user')->whereNull('deleted_at');
        if (! empty($d['q'])) {
            $q->where(fn ($w) => $w->where('code', 'like', '%'.$d['q'].'%')->orWhere('depositor', 'like', '%'.$d['q'].'%')->orWhere('company', 'like', '%'.$d['q'].'%')->orWhereHas('user', fn ($u) => $u->where('email', 'like', '%'.$d['q'].'%')));
        }
        if (! empty($d['from'])) {
            $q->whereDate('created_at', '>=', $d['from']);
        }
        if (! empty($d['to'])) {
            $q->whereDate('created_at', '<=', $d['to']);
        }
        if (! empty($d['status'])) {
            $q->where('status', $d['status']);
        }
        $totals = ['received' => (clone $q)->where('bank_received', true)->sum('amount'), 'waiting' => (clone $q)->where('bank_received', false)->sum('amount')];
        $topups = $q->latest()->paginate(40)->withQueryString();
        $audits = DB::table('topup_audits')->whereIn('topup_id', $topups->pluck('id'))->latest('id')->get()->groupBy('topup_id');

        return view('admin.topups.index', compact('topups', 'totals', 'audits'));
    }

    public function update(Request $request, Topup $topup, TopupAdmin $service)
    {
        $d = $request->validate(['action' => 'required|in:revoke,cancel,restore,delete,edit,bank,vat,invoice,vat_sms', 'amount' => 'required_if:action,edit|integer|min:1000|max:100000000', 'depositor' => 'required_if:action,edit|string|max:60', 'company' => 'nullable|string|max:160', 'value' => 'required_if:action,bank,vat,invoice|boolean']);
        try {
            $service->apply($topup, $request->user(), $d);
        } catch (OrderInputException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return back()->with('status', '처리했습니다.');
    }

    public function review(Request $request, Topup $topup, PrepaidWallet $wallet)
    {
        $data = $request->validate(['status' => ['required', 'in:confirmed,rejected']]);
        $wallet->review($topup, $request->user(), $data['status']);

        return back()->with('status', '충전 요청을 처리했습니다.');
    }
}
