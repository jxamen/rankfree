<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\MarketingProduct;
use App\Models\User;
use App\Models\UserCoupon;
use Illuminate\Http\Request;

/**
 * 관리자 쿠폰 관리(26) — 쿠폰 CRUD + 발급(특정 회원·전체 회원) + 발급 내역·회수.
 * ⚠️ 발송(문자·메일) 기능은 붙이지 않는다 — 쿠폰 안내는 회원이 콘솔 쿠폰함에서 확인.
 */
class CouponController extends Controller
{
    public function index(Request $request)
    {
        $coupons = Coupon::withCount([
            'userCoupons',
            'userCoupons as used_count' => fn ($q) => $q->whereNotNull('used_at'),
        ])->latest()->paginate(20)->withQueryString();

        $stats = [
            'active' => Coupon::where('is_active', true)->count(),
            'issued' => UserCoupon::count(),
            'used' => UserCoupon::whereNotNull('used_at')->count(),
            'discounted' => (int) \App\Models\MarketingOrder::whereNotNull('user_coupon_id')
                ->where('status', '!=', 'canceled')->sum('discount_amount'),
        ];

        return view('admin.coupons.index', [
            'coupons' => $coupons,
            'stats' => $stats,
            'products' => MarketingProduct::orderBy('title')->get(['id', 'title']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateCoupon($request);
        $data['created_by'] = $request->user()->id;
        $coupon = Coupon::create($data);

        return redirect()->route('admin.coupons.show', $coupon)
            ->with('status', "쿠폰 '{$coupon->name}'(코드 {$coupon->code})을(를) 만들었습니다.");
    }

    public function update(Request $request, Coupon $coupon)
    {
        $coupon->update($this->validateCoupon($request));

        return back()->with('status', "쿠폰 '{$coupon->name}'을(를) 수정했습니다.");
    }

    public function toggle(Coupon $coupon)
    {
        $coupon->update(['is_active' => ! $coupon->is_active]);

        return back()->with('status', "'{$coupon->name}' 쿠폰을 ".($coupon->is_active ? '활성' : '중지')."했습니다.");
    }

    public function destroy(Coupon $coupon)
    {
        if ($coupon->userCoupons()->whereNotNull('used_at')->exists()) {
            return back()->withErrors(['coupon' => "'{$coupon->name}' 쿠폰은 사용 이력이 있어 삭제할 수 없습니다. 대신 '중지'로 전환하세요."]);
        }
        $name = $coupon->name;
        $coupon->delete();   // 미사용 발급분은 함께 삭제(cascade)

        return redirect()->route('admin.coupons')->with('status', "쿠폰 '{$name}'을(를) 삭제했습니다.");
    }

    /** 상세 — 발급 내역 + 회원 검색 발행 + 전체 발급. */
    public function show(Request $request, Coupon $coupon)
    {
        $coupon->loadCount(['userCoupons', 'userCoupons as used_count' => fn ($q) => $q->whereNotNull('used_at')]);

        // 회원 검색(이름·이메일·전화) — 결과마다 발행 버튼. 이미 보유한 회원은 표시만.
        $memberQ = trim((string) $request->query('member', ''));
        $members = collect();
        if ($memberQ !== '') {
            $digits = preg_replace('/[^0-9]/', '', $memberQ);
            $members = User::where(function ($w) use ($memberQ, $digits) {
                $w->where('name', 'like', "%{$memberQ}%")->orWhere('email', 'like', "%{$memberQ}%");
                if ($digits !== '') {
                    $w->orWhere('phone', 'like', "%{$digits}%");
                }
            })->orderBy('name')->limit(20)->get();
        }
        $ownedUserIds = $members->isNotEmpty()
            ? $coupon->userCoupons()->whereIn('user_id', $members->pluck('id'))->pluck('user_id')->all()
            : [];

        return view('admin.coupons.show', [
            'coupon' => $coupon,
            'issued' => $coupon->userCoupons()->with(['user', 'issuer', 'order'])->latest()->paginate(20)->withQueryString(),
            'memberQ' => $memberQ,
            'members' => $members,
            'ownedUserIds' => $ownedUserIds,
            'notIssuedCount' => User::whereDoesntHave('userCoupons', fn ($q) => $q->where('coupon_id', $coupon->id))->count(),
        ]);
    }

    /** 특정 회원에게 발행. */
    public function issue(Request $request, Coupon $coupon)
    {
        $userId = (int) $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']])['user_id'];
        $user = User::findOrFail($userId);

        if ($coupon->userCoupons()->where('user_id', $user->id)->exists()) {
            return back()->withErrors(['issue' => "'{$user->name}' 회원은 이미 이 쿠폰을 보유하고 있습니다(1인 1매)."]);
        }
        if (($remain = $coupon->remainingIssuance()) !== null && $remain < 1) {
            return back()->withErrors(['issue' => '발급 수량 제한에 도달했습니다. 수량을 늘리거나 비우세요.']);
        }

        $coupon->userCoupons()->create([
            'user_id' => $user->id,
            'source' => 'admin',
            'issued_by' => $request->user()->id,
            'expires_at' => $coupon->expiresAtForIssue(),
        ]);

        return back()->with('status', "'{$user->name}' 회원에게 '{$coupon->name}' 쿠폰을 발행했습니다.");
    }

    /** 전체 회원 일괄 발급 — 미보유 회원 전원(수량 제한이 있으면 남은 수까지만). */
    public function issueAll(Request $request, Coupon $coupon)
    {
        $adminId = $request->user()->id;
        $expiresAt = $coupon->expiresAtForIssue();
        $remain = $coupon->remainingIssuance();   // null=무제한
        $issued = 0;
        $capped = false;

        User::whereDoesntHave('userCoupons', fn ($q) => $q->where('coupon_id', $coupon->id))
            ->orderBy('id')
            ->chunkById(500, function ($users) use ($coupon, $adminId, $expiresAt, &$remain, &$issued, &$capped) {
                if ($remain !== null && $remain <= 0) {
                    $capped = true;

                    return false;
                }
                if ($remain !== null && $users->count() > $remain) {
                    $users = $users->take($remain);
                    $capped = true;
                }
                $now = now();
                UserCoupon::insert($users->map(fn ($u) => [
                    'coupon_id' => $coupon->id,
                    'user_id' => $u->id,
                    'source' => 'admin',
                    'issued_by' => $adminId,
                    'expires_at' => $expiresAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
                $issued += $users->count();
                if ($remain !== null) {
                    $remain -= $users->count();
                }
            });

        $msg = "전체 발급 완료 — {$issued}명에게 발행했습니다.";
        if ($capped) {
            $msg .= ' (발급 수량 제한으로 일부 회원은 제외)';
        }
        if ($issued === 0 && ! $capped) {
            $msg = '발급 대상이 없습니다(전 회원이 이미 보유).';
        }

        return back()->with('status', $msg);
    }

    /** 미사용 발급분 회수. */
    public function revoke(UserCoupon $userCoupon)
    {
        if ($userCoupon->used_at) {
            return back()->withErrors(['revoke' => '이미 사용된 쿠폰은 회수할 수 없습니다.']);
        }
        $name = $userCoupon->user?->name ?? '(탈퇴 회원)';
        $userCoupon->delete();

        return back()->with('status', "'{$name}' 회원의 발급분을 회수했습니다.");
    }

    private function validateCoupon(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'discount_type' => ['required', 'in:amount,percent'],
            'discount_value' => ['required', 'numeric', 'min:1'],
            'max_discount' => ['nullable', 'numeric', 'min:1'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'valid_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'is_downloadable' => ['nullable', 'boolean'],
            'max_issuance' => ['nullable', 'integer', 'min:1'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:marketing_products,id'],
            'memo' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($data['discount_type'] === 'percent' && (float) $data['discount_value'] > 100) {
            throw \Illuminate\Validation\ValidationException::withMessages(['discount_value' => '정률 할인은 100% 를 넘을 수 없습니다.']);
        }

        $data['is_downloadable'] = $request->boolean('is_downloadable');
        $data['is_active'] = $request->boolean('is_active');
        $data['min_order_amount'] = $data['min_order_amount'] ?? 0;
        // 정액 쿠폰에 최대 할인액은 의미 없음 — 비워서 혼동 방지
        if ($data['discount_type'] === 'amount') {
            $data['max_discount'] = null;
        }
        $data['product_ids'] = ! empty($data['product_ids']) ? array_values(array_map('intval', $data['product_ids'])) : null;

        return $data;
    }
}
