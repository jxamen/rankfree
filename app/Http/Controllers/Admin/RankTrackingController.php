<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlaceRankSlot;
use App\Models\ShopRankSlot;
use Illuminate\Http\Request;

/**
 * 순위추적 관리(관리자) — 회원들이 등록한 플레이스·쇼핑 순위추적 슬롯을 전체 조회한다.
 * 콘솔의 /rank·/shop-rank 는 로그인 사용자 본인 것만 보지만, 여기서는 전 회원 슬롯을 본다(열람 전용).
 */
class RankTrackingController extends Controller
{
    /** 플레이스 순위추적 슬롯 전체 목록. */
    public function place(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $active = (string) $request->query('active', '');   // ''=전체 · '1'=활성 · '0'=중지
        $userId = (int) $request->query('user', 0);          // 회원(아이디 클릭) 필터 — 업체별 추적 리스트

        $slots = PlaceRankSlot::with('user:id,name,email')
            ->when($userId > 0, fn ($x) => $x->where('user_id', $userId))
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w
                ->where('keyword', 'like', $this->like($q))
                ->orWhere('place_name', 'like', $this->like($q))
                ->orWhereHas('user', fn ($u) => $u
                    ->where('name', 'like', $this->like($q))->orWhere('email', 'like', $this->like($q)))))
            ->when($active === '1', fn ($x) => $x->where('is_active', true))
            ->when($active === '0', fn ($x) => $x->where('is_active', false))
            ->latest('id')->paginate(30)->withQueryString();

        return view('admin.tracking.index', [
            'mode' => 'place',
            'title' => '플레이스 추적',
            'desc' => '회원들이 등록한 플레이스 순위추적 슬롯 전체를 조회합니다',
            'routeName' => 'admin.place-tracking',
            'slots' => $slots,
            'stats' => $this->stats(PlaceRankSlot::query()),
            'q' => $q,
            'active' => $active,
            'userId' => $userId,
            'filterUser' => $userId > 0 ? \App\Models\User::find($userId, ['id', 'name', 'email']) : null,
        ]);
    }

    /** 쇼핑 순위추적 슬롯 전체 목록. */
    public function shop(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $active = (string) $request->query('active', '');
        $userId = (int) $request->query('user', 0);

        $slots = ShopRankSlot::with('user:id,name,email')
            ->when($userId > 0, fn ($x) => $x->where('user_id', $userId))
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w
                ->where('keyword', 'like', $this->like($q))
                ->orWhere('product_title', 'like', $this->like($q))
                ->orWhere('mall_name', 'like', $this->like($q))
                ->orWhereHas('user', fn ($u) => $u
                    ->where('name', 'like', $this->like($q))->orWhere('email', 'like', $this->like($q)))))
            ->when($active === '1', fn ($x) => $x->where('is_active', true))
            ->when($active === '0', fn ($x) => $x->where('is_active', false))
            ->latest('id')->paginate(30)->withQueryString();

        return view('admin.tracking.index', [
            'mode' => 'shop',
            'title' => '쇼핑 추적',
            'desc' => '회원들이 등록한 쇼핑 순위추적 슬롯 전체를 조회합니다',
            'routeName' => 'admin.shop-tracking',
            'slots' => $slots,
            'stats' => $this->stats(ShopRankSlot::query()),
            'q' => $q,
            'active' => $active,
            'userId' => $userId,
            'filterUser' => $userId > 0 ? \App\Models\User::find($userId, ['id', 'name', 'email']) : null,
        ]);
    }

    /** 목록 상단 통계 — 전체·활성·등록 회원 수·최근 7일 확인. */
    private function stats($query): array
    {
        return [
            'total' => (clone $query)->count(),
            'active' => (clone $query)->where('is_active', true)->count(),
            'users' => (clone $query)->distinct('user_id')->count('user_id'),
            'checked7' => (clone $query)->where('last_checked_at', '>=', now()->subDays(7))->count(),
        ];
    }

    /** LIKE 와일드카드 이스케이프. */
    private function like(string $s): string
    {
        return '%'.addcslashes($s, '\\%_').'%';
    }
}
