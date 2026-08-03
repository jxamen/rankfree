<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Place\RankSlotService;
use App\Domain\Shopping\ShopRankSlotService;
use App\Http\Controllers\Controller;
use App\Models\PlaceRankSlot;
use App\Models\ShopRankSlot;
use App\Models\User;
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
                // 카드 날짜별 셀 표시 상한(60일)에 맞춰 로드 — limit(2)면 2일치만 보임
                ->with(['records' => fn ($r) => $r->reorder()->orderByDesc('checked_date')->limit(60)])
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
            'filterUser' => $userId > 0 ? User::find($userId, ['id', 'name', 'email']) : null,
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
                // 카드 날짜별 셀 표시 상한(60일)에 맞춰 로드
                ->with(['records' => fn ($r) => $r->reorder()->orderByDesc('checked_date')->limit(60)])
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
            'filterUser' => $userId > 0 ? User::find($userId, ['id', 'name', 'email']) : null,
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
        $msg = match (true) {
            $found => "{$r['rank']}위",
            ! empty($r['queued']) => '아직 확인 중입니다. 잠시 후 다시 확인해 주세요.',
            ! empty($r['blocked']) => '아직 확인 중입니다. 잠시 후 다시 확인해 주세요.',
            default => "{$max}위 밖",
        };

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

    // ── 운영자 등록/수정(2026-07-27) — 회원 대신 슬롯을 등록·수정한다. 소유권 검사 없음, 한도 무시. ──

    /** [운영자 등록] 플레이스 — 대상 회원(user_id)에게 등록. 한도 무시(운영자 권한). */
    public function storePlace(Request $request, RankSlotService $service)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'place' => ['required', 'string', 'max:1000'],
            'keywords' => ['required', 'array', 'min:1'],
            'keywords.*' => ['nullable', 'string', 'max:100'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);
        $target = User::findOrFail($data['user_id']);
        try {
            $res = $service->addMany($target, $data['place'], $data['keywords'], $data['label'] ?? null, true);
        } catch (\DomainException $e) {
            return back()->withErrors(['place' => $e->getMessage()])->withInput();
        }
        $t0 = microtime(true);
        foreach ($res['created'] as $slot) {   // 등록 즉시 첫 순위 수집(20초 예산)
            if (microtime(true) - $t0 > 20) {
                break;
            }
            try {
                $service->run($slot);
            } catch (\Throwable) {
            }
        }
        $n = count($res['created']);
        $msg = $n > 0 ? "{$target->name} · 키워드 {$n}개 추적 추가됨." : '추가된 키워드가 없습니다.';
        if (count($res['skipped'])) {
            $msg .= ' (중복 제외: '.implode(', ', $res['skipped']).')';
        }

        return back()->with('status', $msg);
    }

    /** [운영자 수정] 플레이스 슬롯 — 소유권 검사 없이, 중복은 슬롯 소유 회원 기준. */
    public function updatePlace(Request $request, PlaceRankSlot $slot, RankSlotService $service)
    {
        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:100'],
            'place' => ['required', 'string', 'max:1000'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);
        $kw = trim($data['keyword']);
        $placeInput = trim($data['place']);
        $placeChanged = $placeInput !== (string) $slot->place_url && $placeInput !== (string) $slot->place_id;
        $place = $placeChanged ? $service->resolvePlace($placeInput) : null;
        $newPlaceId = $placeChanged ? $place['place_id'] : $slot->place_id;
        $newPlaceName = $placeChanged ? $place['place_name'] : $slot->place_name;

        $dupe = PlaceRankSlot::where('user_id', $slot->user_id)->where('id', '!=', $slot->id)->where('keyword', $kw)
            ->when($newPlaceId, fn ($q) => $q->where('place_id', $newPlaceId), fn ($q) => $q->where('place_name', $newPlaceName))
            ->exists();
        if ($dupe) {
            return back()->withErrors(['keyword' => "'{$kw}' 는 이미 같은 플레이스에서 추적 중입니다."])->withInput();
        }

        $slot->update(array_merge(
            ['keyword' => $kw, 'label' => $data['label'] !== null && trim($data['label']) !== '' ? trim($data['label']) : null],
            $placeChanged ? ['place_id' => $place['place_id'], 'place_name' => $place['place_name'], 'place_url' => $place['place_url'], 'category' => $place['category'] ?: 'place'] : [],
        ));

        return back()->with('status', '수정했습니다. 다음 확인부터 변경된 기준으로 기록됩니다.');
    }

    /** [운영자] 플레이스 업체명 미리보기(AJAX). */
    public function resolvePlace(Request $request, RankSlotService $service)
    {
        $input = trim((string) $request->query('place', ''));
        if ($input === '') {
            return response()->json(['ok' => false, 'message' => '플레이스 URL 또는 ID 를 입력하세요.'], 422);
        }
        $p = $service->resolvePlace($input);

        return response()->json(['ok' => (bool) ($p['place_id'] || $p['place_name']), 'place_id' => $p['place_id'], 'place_name' => $p['place_name'], 'category' => $p['category'], 'place_url' => $p['place_url']]);
    }

    /** [운영자 등록] 쇼핑 — 대상 회원에게 등록. 한도 무시. */
    public function storeShop(Request $request, ShopRankSlotService $service)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'target' => ['required', 'string', 'max:500'],
            'keywords' => ['required', 'array', 'min:1'],
            'keywords.*' => ['nullable', 'string', 'max:120'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);
        $target = User::findOrFail($data['user_id']);
        try {
            $res = $service->addMany($target, $data['target'], $data['keywords'], $data['label'] ?? null, true);
        } catch (\DomainException $e) {
            return back()->withErrors(['target' => $e->getMessage()])->withInput();
        }
        $t0 = microtime(true);
        foreach ($res['created'] as $slot) {
            if (microtime(true) - $t0 > 20) {
                break;
            }
            try {
                $service->run($slot);
            } catch (\Throwable) {
            }
        }
        $n = count($res['created']);
        $msg = $n > 0 ? "{$target->name} · 키워드 {$n}개 추적 추가됨." : '추가된 키워드가 없습니다.';
        if (count($res['skipped'])) {
            $msg .= ' (중복 제외: '.implode(', ', $res['skipped']).')';
        }

        return back()->with('status', $msg);
    }

    /** [운영자 수정] 쇼핑 슬롯 — 소유권 없이, 중복은 슬롯 소유 회원 기준. */
    public function updateShop(Request $request, ShopRankSlot $slot, ShopRankSlotService $service)
    {
        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:120'],
            'target' => ['required', 'string', 'max:500'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);
        $t = $service->resolve($data['target']);
        if ($t['product_id'] === '' && $t['mall_name'] === '') {
            return back()->withErrors(['target' => '상품 URL 또는 업체명을 확인하세요.'])->withInput();
        }
        $dup = ShopRankSlot::where('user_id', $slot->user_id)->where('keyword', $data['keyword'])->where('id', '!=', $slot->id)
            ->where(fn ($q) => $t['product_id'] !== '' ? $q->where('product_id', $t['product_id']) : $q->where('mall_name', $t['mall_name']))
            ->exists();
        if ($dup) {
            return back()->withErrors(['keyword' => '이미 추적 중인 키워드입니다.'])->withInput();
        }
        $slot->update([
            'keyword' => $data['keyword'],
            'label' => $data['label'] ?: null,
            'target_type' => $t['type'],
            'product_id' => $t['product_id'] ?: null,
            'mall_name' => $t['mall_name'] ?: null,
            'product_url' => $t['url'] ?: null,
        ]);

        return back()->with('status', '수정했습니다.');
    }

    /** [운영자] 쇼핑 대상 미리보기(AJAX). */
    public function resolveShop(Request $request, ShopRankSlotService $service)
    {
        $input = trim((string) $request->query('target', ''));
        if ($input === '') {
            return response()->json(['ok' => false, 'message' => 'target 이 비었습니다.'], 422);
        }
        $t = $service->resolve($input);
        $label = $t['product_id'] !== '' ? '상품 ID '.$t['product_id'] : '업체명 '.$t['mall_name'];

        return response()->json(['ok' => $t['product_id'] !== '' || $t['mall_name'] !== ''] + $t + ['label' => $label]);
    }

    /** 운영자 플레이스 슬롯 삭제 — 소유권 체크 없이 전권(어드민 열람 화면). */
    public function destroyPlace(PlaceRankSlot $slot)
    {
        $slot->delete();

        return back()->with('status', '추적 슬롯을 삭제했습니다.');
    }

    /** 운영자 쇼핑 슬롯 삭제 — 소유권 체크 없이 전권. */
    public function destroyShop(ShopRankSlot $slot)
    {
        $slot->delete();

        return back()->with('status', '추적 슬롯을 삭제했습니다.');
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
