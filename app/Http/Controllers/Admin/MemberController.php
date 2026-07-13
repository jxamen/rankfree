<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MemberGrade;
use App\Models\OperatorRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** 관리자 회원 관리 — 목록·검색·등급/역할/구독 편집. */
class MemberController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $gradeId = $request->query('grade');
        $role = $request->query('role');

        $query = User::with(['grade', 'operatorRole'])->latest();

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%");
            });
        }
        if ($gradeId === 'none') {
            $query->whereNull('grade_id');
        } elseif (is_numeric($gradeId)) {
            $query->where('grade_id', (int) $gradeId);
        }
        if ($role === 'operator') {
            $query->whereNotNull('operator_role_id');
        } elseif ($role === 'member') {
            $query->whereNull('operator_role_id');
        }

        $members = $query->paginate(20)->withQueryString();

        $stats = [
            'total' => User::count(),
            'paid' => User::whereHas('grade', fn ($g) => $g->where('is_paid', true))->count(),
            'operators' => User::whereNotNull('operator_role_id')->count(),
            'new7d' => User::where('created_at', '>=', now()->subDays(7))->count(),
        ];

        return view('admin.members', [
            'members' => $members,
            'grades' => MemberGrade::orderBy('tier')->get(),
            'roles' => OperatorRole::orderBy('level')->get(),
            'stats' => $stats,
            'q' => $q,
            'gradeId' => $gradeId,
            'role' => $role,
        ]);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'grade_id' => ['nullable', 'exists:member_grades,id'],
            'subscription_expires_at' => ['nullable', 'date'],
            'operator_role_id' => ['nullable', 'exists:operator_roles,id'],
        ]);

        $actor = $request->user();
        $isSuper = $actor->isSuperAdmin();

        // 운영자 권한(역할) 부여·회수는 슈퍼관리자만
        $payload = [
            'grade_id' => $data['grade_id'] ?? null,
            'subscription_expires_at' => ($data['subscription_expires_at'] ?? null)
                ? Carbon::parse($data['subscription_expires_at'])->endOfDay()
                : null,
        ];

        if ($isSuper) {
            // 자기 자신의 슈퍼 권한을 실수로 회수하지 못하게 방지
            if ($user->id === $actor->id && $actor->role === 'super' && ($data['operator_role_id'] ?? null) === null) {
                // role=super 는 유지, operator_role 만 반영
                $payload['operator_role_id'] = $data['operator_role_id'] ?? null;
            } else {
                $payload['operator_role_id'] = $data['operator_role_id'] ?? null;
                // 운영자 역할이 지정되면 role 을 operator 로, 없으면 user 로 (super 계정은 건드리지 않음)
                if ($user->role !== 'super') {
                    $payload['role'] = ($data['operator_role_id'] ?? null) ? 'operator' : 'user';
                }
            }
        }

        $user->update($payload);

        return back()->with('status', "'{$user->name}' 회원 정보를 저장했습니다.");
    }
}
