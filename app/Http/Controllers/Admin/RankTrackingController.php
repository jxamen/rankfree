<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Place\RankSlotService;
use App\Domain\Shopping\ShopRankSlotService;
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

        // 회원(업체) 보기 — 검색·페이지네이션 없이 그 업체의 전체 키워드를 콘솔형 카드로 전부 표시
        $slots = $userId > 0
            ? PlaceRankSlot::with('user:id,name,email', 'records')->where('user_id', $userId)->latest('id')->get()
            : PlaceRankSlot::with('user:id,name,email')
                ->with(['records' => fn ($r) => $r->reorder()->orderByDesc('checked_date')->limit(2)])
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

        $slots = $userId > 0
            ? ShopRankSlot::with('user:id,name,email', 'records')->where('user_id', $userId)->latest('id')->get()
            : ShopRankSlot::with('user:id,name,email')
                ->with(['records' => fn ($r) => $r->reorder()->orderByDesc('checked_date')->limit(2)])
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

    /** 플레이스 순위체크 중단/재개 — 자동 중단(3일 미노출)분 재개 포함, 삭제 아님. */
    public function togglePlace(PlaceRankSlot $slot)
    {
        $slot->update(['is_active' => ! $slot->is_active]);

        return back()->with('status', "'{$slot->keyword}' 순위체크를 ".($slot->is_active ? '재개했습니다.' : '중단했습니다(기록 유지).'));
    }

    /** 쇼핑 순위체크 중단/재개. */
    public function toggleShop(ShopRankSlot $slot)
    {
        $slot->update(['is_active' => ! $slot->is_active]);

        return back()->with('status', "'{$slot->keyword}' 순위체크를 ".($slot->is_active ? '재개했습니다.' : '중단했습니다(기록 유지).'));
    }

    /**
     * 플레이스 개별 순위체크 — 슬롯 1개를 즉시 동기 조회·기록(콘솔 run 미러, 소유권·1시간 제한 없음).
     * 운영자 판단으로 임의 회원 슬롯을 강제 재확인한다. 카드 순위체크 버튼이 JSON 으로 호출.
     * 상품명·플레이스명 등은 이 순위체크가 검색결과에서 찾을 때 함께 채워진다.
     */
    public function runPlace(Request $request, PlaceRankSlot $slot, RankSlotService $service)
    {
        $r = $service->run($slot);
        $msg = ! empty($r['blocked'])
            ? '조회가 일시적으로 제한됐습니다 (nCaptcha 토큰 재발급 필요).'
            : (! empty($r['found']) ? $slot->keyword.' 순위 '.$r['rank'].'위' : '300위 밖입니다.');

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => empty($r['blocked']),
                'found' => ! empty($r['found']),
                'blocked' => ! empty($r['blocked']),
                'rank' => (int) ($r['rank'] ?? 0),
                'message' => $msg,
            ]);
        }

        return back()->with('status', "「{$slot->keyword}」 {$msg}");
    }

    /** 쇼핑 개별 순위체크 — 슬롯 1개 즉시 동기 조회·기록(콘솔 run 미러). 상품명·가격은 검색결과 매칭 시 채워진다. */
    public function runShop(Request $request, ShopRankSlot $slot, ShopRankSlotService $service)
    {
        $r = $service->run($slot);
        $max = (int) config('rankfree.shopping.display', 100) * (int) config('rankfree.shopping.max_pages', 10);
        $found = ! empty($r['found']);
        $msg = $found
            ? "{$r['rank']}위"
            : (! empty($r['blocked']) ? 'API 한도로 조회가 지연됩니다. 잠시 후 재시도하세요.' : "{$max}위 밖");

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => empty($r['error']),
                'found' => $found,
                'blocked' => ! empty($r['blocked']),
                'rank' => (int) ($r['rank'] ?? 0),
                'message' => $msg,
            ]);
        }

        return back()->with('status', "「{$slot->keyword}」 {$msg}");
    }

    /**
     * 쇼핑 제목 수집 — 확장이 상품페이지(스마트스토어/브랜드)에서 긁어온 상품정보를 슬롯에 저장한다.
     * 미노출(순위 밖) 상품은 순위체크로 제목이 안 붙으므로 이 경로로 채운다. shop-keyword refreshProductInfo 미러.
     */
    public function productInfoShop(Request $request, ShopRankSlot $slot)
    {
        $data = $request->validate([
            'info' => 'nullable|array',
            'info.channel_product_id' => 'nullable|string|max:40',
            'info.title' => 'nullable|string|max:300',
            'info.brand' => 'nullable|string|max:120',
            'info.mall_name' => 'nullable|string|max:150',
            'info.price' => 'nullable|integer|min:0|max:2000000000',
            'info.category' => 'nullable|string|max:191',
            'info.thumbnail_url' => 'nullable|string|max:500',
            'info.seller_tags' => 'nullable|array|max:60',
            'info.seller_tags.*' => 'nullable|string|max:80',
        ]);

        $info = (array) ($data['info'] ?? []);
        $title = trim((string) ($info['title'] ?? ''));
        if ($title === '') {
            return response()->json(['ok' => false, 'message' => '상품 제목을 가져오지 못했습니다 — 스마트스토어/브랜드 상품만 수집됩니다.']);
        }

        // 명시적 수집이라 기존 값도 덮어쓴다(제목 재수집 겸용). 부가로 몰·가격·카테고리도 있으면 채움.
        $slot->product_title = mb_substr($title, 0, 300);
        if (($m = trim((string) ($info['mall_name'] ?? ''))) !== '') {
            $slot->mall_name = mb_substr($m, 0, 150);
        }
        if ((int) ($info['price'] ?? 0) > 0) {
            $slot->last_price = (int) $info['price'];
        }
        if (($c = trim((string) ($info['category'] ?? ''))) !== '') {
            $slot->category = mb_substr($c, 0, 191);
        }
        if (! $slot->product_id && ($pid = trim((string) ($info['channel_product_id'] ?? ''))) !== '') {
            $slot->product_id = $pid;
        }
        $slot->save();

        return response()->json([
            'ok' => true,
            'title' => $slot->product_title,
            'mall' => $slot->mall_name,
            'price' => $slot->last_price,
            'message' => "제목을 수집했습니다: {$slot->product_title}",
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
