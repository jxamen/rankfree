<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RewardMedia;
use App\Models\RewardMission;
use App\Support\RewardDay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 매체(벤더) 관리 — 리워드 참여를 공급하는 채널.
 * 매체마다 **지급 단가**와 **처리 능력**이 다르므로 단가·초당 한도·배분 비율을 여기서 정한다(design-04 §2-1).
 */
class RewardMediaController extends Controller
{
    public function index()
    {
        $day = RewardDay::current();

        $media = RewardMedia::query()->orderBy('type')->orderBy('name')->get();

        // 오늘 매체별 소진 — 배분이 실제로 어떻게 나뉘고 있는지 보여준다
        $todayUsed = DB::table('reward_participation_logs')
            ->where('stat_date', $day)->where('result', 'correct')
            ->groupBy('media_id')->selectRaw('media_id, COUNT(*) as c')->pluck('c', 'media_id');

        return view('admin.reward.media.index', [
            'media' => $media,
            'todayUsed' => $todayUsed,
            'day' => $day,
            'allocCounts' => DB::table('reward_media_allocations')->where('is_active', true)
                ->groupBy('media_id')->selectRaw('media_id, COUNT(*) as c')->pluck('c', 'media_id'),
        ]);
    }

    public function edit(RewardMedia $medium)
    {
        return view('admin.reward.media.form', [
            'medium' => $medium,
            'allocations' => DB::table('reward_media_allocations')
                ->where('media_id', $medium->id)->orderBy('scope')->orderBy('scope_key')->get(),
            'payouts' => DB::table('reward_media_payouts')
                ->where('media_id', $medium->id)->orderBy('kind')->get(),
            'missions' => RewardMission::query()->whereIn('status', ['active', 'draft', 'paused'])
                ->orderByDesc('id')->limit(50)->get(['id', 'title', 'daily_quota']),
            'kinds' => RewardMission::query()->select('kind')->distinct()->pluck('kind')->filter()->values(),
        ]);
    }

    public function create()
    {
        return view('admin.reward.media.form', [
            'medium' => new RewardMedia(['type' => RewardMedia::TYPE_VENDOR_API, 'rate_limit_rps' => 100, 'verify_mode' => 'server']),
            'allocations' => collect(),
            'payouts' => collect(),
            'missions' => collect(),
            'kinds' => collect(),
        ]);
    }

    public function store(Request $request)
    {
        $medium = RewardMedia::query()->create($this->validated($request, null));

        // 등록과 동시에 매체 전용 키를 발급한다 — 매체를 만들었는데 호출 수단이 없는 상태를 없앤다
        $medium->issueKey();

        return redirect()->route('admin.reward.media.edit', $medium)
            ->with('status', '제휴 매체를 등록하고 API 키를 발급했습니다. 아래 키를 매체에 전달하세요.');
    }

    /** 키 재발급 — 유출·교체 시. 이전 키는 즉시 무효가 된다. */
    public function regenerateKey(RewardMedia $medium)
    {
        $medium->issueKey();

        return redirect()->route('admin.reward.media.edit', $medium)
            ->with('status', 'API 키를 재발급했습니다. 이전 키는 즉시 사용할 수 없습니다.');
    }

    public function update(Request $request, RewardMedia $medium)
    {
        $medium->update($this->validated($request, $medium->id));

        if ($request->has('alloc_submitted')) {
            $this->saveAllocations($request, $medium);
        }
        if ($request->has('payout_submitted')) {
            $this->savePayouts($request, $medium);
        }

        return redirect()->route('admin.reward.media.edit', $medium)->with('status', '매체 설정을 저장했습니다.');
    }

    public function toggle(RewardMedia $medium)
    {
        $medium->update(['is_active' => ! $medium->is_active]);

        return back()->with('status', $medium->name.' 매체를 '.($medium->is_active ? '활성화' : '비활성화').'했습니다.');
    }

    private function validated(Request $request, ?int $id): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/',
                'unique:reward_media,slug'.($id ? ','.$id : '')],
            'type' => ['required', 'in:miniapp,vendor_api'],
            'payout_unit_price' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'rate_limit_rps' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'verify_mode' => ['required', 'in:server,vendor'],
        ], [], [
            'slug' => '식별자', 'payout_unit_price' => '지급 단가', 'rate_limit_rps' => '초당 호출 한도',
        ]);

        $data['payout_unit_price'] = (int) ($data['payout_unit_price'] ?? 0);
        $data['rate_limit_rps'] = (int) ($data['rate_limit_rps'] ?? 100);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    /**
     * 배분 규칙 저장 — 좁은 범위가 우선한다(mission > kind > all).
     * 비율·상한을 둘 다 비우면 그 규칙은 지운다(= 제한 없음으로 되돌림).
     */
    private function saveAllocations(Request $request, RewardMedia $medium): void
    {
        $request->validate([
            'alloc' => ['nullable', 'array', 'max:50'],
            'alloc.*.scope' => ['nullable', 'in:all,kind,mission'],
            'alloc.*.scope_key' => ['nullable', 'string', 'max:40'],
            'alloc.*.ratio' => ['nullable', 'integer', 'min:0', 'max:100'],
            'alloc.*.max_per_day' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'alloc.*.min_per_day' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ], [], ['alloc.*.ratio' => '비율']);

        $keep = [];
        foreach ((array) $request->input('alloc', []) as $row) {
            $scope = (string) ($row['scope'] ?? 'all');
            $key = $scope === 'all' ? '' : trim((string) ($row['scope_key'] ?? ''));
            if ($scope !== 'all' && $key === '') {
                continue;
            }

            $ratio = ($row['ratio'] ?? '') === '' ? null : (int) $row['ratio'];
            $max = ($row['max_per_day'] ?? '') === '' ? null : (int) $row['max_per_day'];
            if ($ratio === null && $max === null) {
                continue;   // 상한이 없는 규칙은 의미가 없다
            }

            DB::table('reward_media_allocations')->updateOrInsert(
                ['media_id' => $medium->id, 'scope' => $scope, 'scope_key' => $key],
                ['ratio' => $ratio, 'max_per_day' => $max,
                    'min_per_day' => (int) ($row['min_per_day'] ?? 0),
                    'is_active' => true, 'updated_at' => now(), 'created_at' => now()],
            );
            $keep[] = $scope.':'.$key;
        }

        // 화면에서 지운 규칙은 실제로 삭제한다(남겨두면 조용히 계속 적용된다)
        DB::table('reward_media_allocations')->where('media_id', $medium->id)
            ->get(['id', 'scope', 'scope_key'])
            ->reject(fn ($r) => in_array($r->scope.':'.$r->scope_key, $keep, true))
            ->each(fn ($r) => DB::table('reward_media_allocations')->where('id', $r->id)->delete());
    }

    /**
     * 미션 유형별 지급 단가 저장 — 지출 계산의 입력.
     * 유형 코드가 비면 그 행은 무시하고, 화면에서 지운 유형은 실제로 삭제한다(= 기본 단가로 되돌림).
     */
    private function savePayouts(Request $request, RewardMedia $medium): void
    {
        $request->validate([
            'payout' => ['nullable', 'array', 'max:50'],
            'payout.*.kind' => ['nullable', 'string', 'max:12'],
            'payout.*.unit_price' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ], [], ['payout.*.unit_price' => '유형별 지급 단가']);

        $keep = [];
        foreach ((array) $request->input('payout', []) as $row) {
            $kind = trim((string) ($row['kind'] ?? ''));
            if ($kind === '' || ($row['unit_price'] ?? '') === '') {
                continue;
            }

            DB::table('reward_media_payouts')->updateOrInsert(
                ['media_id' => $medium->id, 'kind' => $kind],
                ['unit_price' => (int) $row['unit_price'], 'updated_at' => now(), 'created_at' => now()],
            );
            $keep[] = $kind;
        }

        DB::table('reward_media_payouts')->where('media_id', $medium->id)
            ->whereNotIn('kind', $keep === [] ? [''] : $keep)->delete();
    }
}
