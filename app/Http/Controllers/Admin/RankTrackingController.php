<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\PlaceTrackRun;
use App\Console\Commands\ShopTrackRun;
use App\Domain\Place\RankSlotService;
use App\Domain\Shopping\ShopRankSlotService;
use App\Http\Controllers\Controller;
use App\Models\PlaceRankSlot;
use App\Models\ShopRankSlot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
            'running' => (bool) Cache::get(PlaceTrackRun::RUNNING_CACHE),
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
            'running' => (bool) Cache::get(ShopTrackRun::RUNNING_CACHE),
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
     * 운영자 판단으로 임의 회원 슬롯을 강제 재확인한다.
     */
    public function runPlace(PlaceRankSlot $slot, RankSlotService $service)
    {
        $r = $service->run($slot);
        $msg = ! empty($r['blocked'])
            ? '조회가 일시적으로 제한됐습니다 (nCaptcha 토큰 재발급 필요).'
            : (! empty($r['found']) ? "{$r['rank']}위" : '300위 밖');

        return back()->with('status', "「{$slot->keyword}」 {$msg}");
    }

    /** 쇼핑 개별 순위체크 — 슬롯 1개 즉시 동기 조회·기록(콘솔 run 미러). */
    public function runShop(ShopRankSlot $slot, ShopRankSlotService $service)
    {
        $r = $service->run($slot);
        $max = (int) config('rankfree.shopping.display', 100) * (int) config('rankfree.shopping.max_pages', 10);
        $msg = ! empty($r['found'])
            ? "{$r['rank']}위"
            : (! empty($r['blocked']) ? 'API 한도로 조회가 지연됩니다. 잠시 후 재시도하세요.' : "{$max}위 밖");

        return back()->with('status', "「{$slot->keyword}」 {$msg}");
    }

    /** 플레이스 전체 순위체크 — 활성 슬롯 배치를 백그라운드로 시작(회원 필터 시 해당 회원만). */
    public function runAllPlace(Request $request)
    {
        return $this->dispatchTrackRun('place', $request);
    }

    /** 쇼핑 전체 순위체크 — 활성 슬롯 배치를 백그라운드로 시작. */
    public function runAllShop(Request $request)
    {
        return $this->dispatchTrackRun('shop', $request);
    }

    /**
     * track-run 배치를 백그라운드 artisan 프로세스로 실행하고 즉시 돌아온다(CafeSeedController::crawl 패턴).
     * 회원 필터(user)가 있으면 --user 로 그 회원 활성 슬롯만 대상으로 한다.
     */
    private function dispatchTrackRun(string $mode, Request $request)
    {
        $isPlace = $mode === 'place';
        $running = $isPlace ? PlaceTrackRun::RUNNING_CACHE : ShopTrackRun::RUNNING_CACHE;
        $label = $isPlace ? '플레이스' : '쇼핑';

        if (Cache::get($running)) {
            return back()->with('status', "{$label} 전체 순위체크가 이미 진행 중입니다 — 완료까지 몇 분 걸립니다. 잠시 후 새로고침하세요.");
        }

        $userId = (int) $request->input('user', 0);
        $signature = ($isPlace ? 'place:track-run' : 'shop:track-run').($userId > 0 ? ' --user='.$userId : '');

        $php = $this->phpBinary();
        $cmd = escapeshellarg($php).' '.escapeshellarg(base_path('artisan')).' '.$signature;
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start "" /B '.$cmd.' >NUL 2>&1', 'r'));
        } else {
            exec($cmd.' > /dev/null 2>&1 &');
        }

        $scope = $userId > 0 ? '이 회원의 활성 슬롯' : '활성 슬롯 전체';

        return back()->with('status', "{$label} 전체 순위체크를 시작했습니다 — {$scope}을 순차 조회합니다. 완료되면 목록에 반영됩니다(몇 분 소요).");
    }

    /** CLI php 경로 — 웹 SAPI 에서는 PHP_BINARY 가 php 가 아닐 수 있어 보정(CafeSeedController 와 동일 규칙·설정 공유). */
    private function phpBinary(): string
    {
        $bin = (string) config('rankfree.cafe_crawl.php_bin', '');
        if ($bin !== '') {
            return $bin;
        }
        if (str_contains(strtolower(basename(PHP_BINARY)), 'php')) {
            return PHP_BINARY;
        }

        return PHP_BINDIR.DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
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
