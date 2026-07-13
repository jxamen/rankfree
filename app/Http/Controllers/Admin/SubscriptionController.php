<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MemberGrade;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** 관리자 구독 관리 — 요금제(등급) CRUD + 유료 구독 회원 현황. */
class SubscriptionController extends Controller
{
    public function index(Request $request)
    {
        $plans = MemberGrade::withCount('users')->orderBy('tier')->get();

        $subscribers = User::with('grade')
            ->whereHas('grade', fn ($g) => $g->where('is_paid', true))
            ->latest('subscription_expires_at')
            ->paginate(15);

        $now = now();
        $stats = [
            'active' => User::whereHas('grade', fn ($g) => $g->where('is_paid', true))
                ->where(fn ($w) => $w->whereNull('subscription_expires_at')->orWhere('subscription_expires_at', '>=', $now))
                ->count(),
            'expiring' => User::whereHas('grade', fn ($g) => $g->where('is_paid', true))
                ->whereBetween('subscription_expires_at', [$now, $now->copy()->addDays(7)])
                ->count(),
            'expired' => User::whereHas('grade', fn ($g) => $g->where('is_paid', true))
                ->whereNotNull('subscription_expires_at')->where('subscription_expires_at', '<', $now)
                ->count(),
            'mrr' => MemberGrade::where('is_paid', true)->withCount('users')->get()
                ->sum(fn ($g) => (int) $g->monthly_price * $g->users_count),
        ];

        return view('admin.subscriptions', compact('plans', 'subscribers', 'stats'));
    }

    /** 요금제(등급) 생성 */
    public function storePlan(Request $request)
    {
        $data = $this->validatePlan($request);
        $data['slug'] = $this->uniqueSlug($data['name']);
        MemberGrade::create($data);

        return back()->with('status', "요금제 '{$data['name']}'을(를) 추가했습니다.");
    }

    /** 요금제 수정 */
    public function updatePlan(Request $request, MemberGrade $plan)
    {
        $plan->update($this->validatePlan($request));

        return back()->with('status', "요금제 '{$plan->name}'을(를) 수정했습니다.");
    }

    public function togglePlan(Request $request, MemberGrade $plan)
    {
        $plan->update(['is_active' => ! $plan->is_active]);

        return back()->with('status', "'{$plan->name}' 요금제를 ".($plan->is_active ? '노출' : '숨김')."했습니다.");
    }

    public function destroyPlan(Request $request, MemberGrade $plan)
    {
        if ($plan->users()->exists()) {
            return back()->withErrors(['plan' => "'{$plan->name}' 요금제를 사용하는 회원이 있어 삭제할 수 없습니다. 먼저 회원 등급을 변경하세요."]);
        }
        $plan->delete();

        return back()->with('status', "요금제 '{$plan->name}'을(를) 삭제했습니다.");
    }

    /** 회원 구독 연장/설정 — months 만큼 연장(현재 만료일 또는 오늘 기준). */
    public function extend(Request $request, User $user)
    {
        $months = (int) $request->validate(['months' => ['required', 'integer', 'min:1', 'max:36']])['months'];
        $base = $user->subscription_expires_at && $user->subscription_expires_at->isFuture()
            ? $user->subscription_expires_at
            : now();
        $user->update(['subscription_expires_at' => $base->copy()->addMonths($months)->endOfDay()]);

        return back()->with('status', "'{$user->name}' 구독을 {$months}개월 연장했습니다. (만료 {$user->subscription_expires_at->format('Y-m-d')})");
    }

    /** 구독 해지 — 무료 등급으로 전환, 만료일 제거. */
    public function cancel(Request $request, User $user)
    {
        $free = MemberGrade::where('is_paid', false)->orderBy('tier')->first();
        $user->update(['grade_id' => $free?->id, 'subscription_expires_at' => null]);

        return back()->with('status', "'{$user->name}' 구독을 해지했습니다.");
    }

    private function validatePlan(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'is_paid' => ['nullable', 'boolean'],
            'tier' => ['required', 'integer', 'min:0', 'max:100'],
            'monthly_price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'rank_slot_limit' => ['required', 'integer', 'min:-1', 'max:100000'],
            'feature_limits' => ['nullable', 'array'],
            'feature_limits.*' => ['nullable', 'integer', 'min:-1', 'max:1000000'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]) + ['is_paid' => $request->boolean('is_paid'), 'is_active' => $request->boolean('is_active')];

        // 알려진 기능 키만, 빈 값은 -1(무제한)로 정규화
        $limits = [];
        foreach (array_keys(MemberGrade::FEATURES) as $key) {
            $v = $request->input("feature_limits.{$key}");
            $limits[$key] = ($v === null || $v === '') ? -1 : (int) $v;
        }
        $data['feature_limits'] = $limits;

        return $data;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'plan';
        $slug = $base;
        $i = 2;
        while (MemberGrade::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
