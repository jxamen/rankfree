<?php

namespace App\Http\Controllers;

use App\Domain\Place\RankSlotService;
use App\Domain\Place\SmartplaceCollector;
use App\Domain\Place\SmartplaceLoginService;
use App\Domain\Place\SmartplaceReportPresenter;
use App\Models\SmartplaceAccount;
use Illuminate\Http\Request;

/**
 * 스마트플레이스 리포트 수집 — 웹 콘솔 (crm ads/smartplace 이식).
 * 광고주 네이버 아이디/비밀번호를 등록 → [수집] 시 자동 로그인(Playwright)으로 세션을 발급·유지 → 5탭 리포트.
 */
class SmartplaceController extends Controller
{
    public function index(Request $request)
    {
        return view('console.smartplace.index', [
            'accounts' => $request->user()->smartplaceAccounts()->latest()->get(),
        ]);
    }

    /**
     * 매장 조회 (AJAX) — 입력한 네이버 아이디/비밀번호로 임시 로그인 후 등록된 스마트플레이스 매장 목록을 반환.
     * 등록 폼에서 URL·업체명을 자동 채우기 위해 사용(1개면 자동 선택, 여러 개면 선택창).
     */
    public function discover(Request $request, SmartplaceCollector $collector, SmartplaceLoginService $loginService)
    {
        $data = $request->validate([
            'naver_id' => ['required', 'string', 'max:100'],
            'naver_pw' => ['required', 'string', 'max:200'],
        ]);

        @set_time_limit(180); // 자동 로그인(최대 ~30초) — 저장 없이 쿠키만 발급
        // 저장하지 않는 임시 계정 인스턴스로 로그인(쿠키만 획득)
        $temp = new SmartplaceAccount(['naver_id' => trim($data['naver_id']), 'place_seq' => '']);
        $temp->naver_pw = $data['naver_pw'];

        $login = $loginService->login($temp, persist: false);
        if (empty($login['ok'])) {
            return response()->json(['ok' => false, 'message' => '자동 로그인 실패 — '.$login['reason']], 422);
        }

        $list = $collector->listBusinesses($login['cookie']);
        $businesses = array_map(fn ($b) => [
            'placeSeq' => $b['placeSeq'],
            'name' => $b['name'],
            'placeId' => $b['placeId'],
            'businessId' => $b['businessId'],
        ], $list['businesses']);

        return response()->json([
            'ok' => true,
            'count' => count($businesses),
            'businesses' => $businesses,
            'message' => count($businesses) === 0
                ? '이 계정에 등록된 스마트플레이스 매장이 없습니다. 계정을 확인하세요.'
                : count($businesses).'개 매장을 찾았습니다.',
        ]);
    }

    /**
     * 계정 등록 — [매장 불러오기]로 선택한 placeSeq + 플레이스 URL(선택). 네이버 자격은 암호화 저장.
     * 플레이스 URL(PC map/m.place)은 resolvePlace 로 m.place 정규화 + 업체명·업종 자동 확정.
     */
    public function store(Request $request, RankSlotService $rank)
    {
        $data = $this->validated($request, creating: true);
        $place = $this->resolvePlaceInput($rank, $data['place'] ?? '');

        $request->user()->smartplaceAccounts()->create([
            'label' => trim($data['label']),
            'place_seq' => trim($data['place_seq']),
            'business_id' => trim((string) ($data['business_id'] ?? '')) ?: null,
            'place_id' => $place['place_id'],
            'place_url' => $place['place_url'],
            'category' => trim($data['category'] ?? '') ?: (string) $place['category'],
            'naver_id' => trim($data['naver_id']),
            'naver_pw' => $data['naver_pw'],
        ]);

        return back()->with('status', "'{$data['label']}' 계정을 등록했습니다. [수집]을 누르면 자동 로그인 후 리포트를 가져옵니다.");
    }

    /** 계정 수정 — 비밀번호는 입력했을 때만 교체(비우면 기존 유지). 자격이 바뀌면 저장된 세션은 폐기. */
    public function update(Request $request, SmartplaceAccount $account, RankSlotService $rank)
    {
        abort_unless($account->user_id === $request->user()->id, 403);
        $data = $this->validated($request, creating: false);
        $place = $this->resolvePlaceInput($rank, $data['place'] ?? '');

        $credChanged = trim($data['naver_id']) !== (string) $account->naver_id;
        $account->fill([
            'label' => trim($data['label']),
            'place_seq' => trim($data['place_seq']),
            'category' => trim($data['category'] ?? '') ?: (string) $place['category'],
            'naver_id' => trim($data['naver_id']),
        ]);
        if (trim((string) ($data['business_id'] ?? '')) !== '') {
            $account->business_id = trim($data['business_id']);
        }
        if ($place['place_id'] !== null || $place['place_url'] !== null) {
            $account->place_id = $place['place_id'];
            $account->place_url = $place['place_url'];
        }
        if (trim((string) ($data['naver_pw'] ?? '')) !== '') {
            $account->naver_pw = $data['naver_pw'];
            $credChanged = true;
        }
        // 아이디/비번이 바뀌면 이전 계정 세션 쿠키는 무효 — 폐기해 다음 수집 때 새로 로그인
        if ($credChanged) {
            $account->cookie = null;
            $account->logged_in_at = null;
        }
        $account->save();

        return back()->with('status', '수정했습니다.');
    }

    /** 플레이스 URL 입력(PC map/m.place/ID) → m.place 정규화·업체명·업종. 빈 입력이면 전부 null. */
    private function resolvePlaceInput(RankSlotService $rank, string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            return ['place_id' => null, 'place_url' => null, 'place_name' => null, 'category' => null];
        }

        return $rank->resolvePlace($input);
    }

    /**
     * 수집 실행 (AJAX) — 저장된 세션으로 우선 수집, 세션 없음/만료면 자동 로그인 후 재수집.
     * 기간 지정 재수집 지원.
     */
    public function collect(Request $request, SmartplaceAccount $account, SmartplaceCollector $collector, SmartplaceLoginService $loginService)
    {
        abort_unless($account->user_id === $request->user()->id, 403);
        $data = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if (trim((string) $account->naver_id) === '' || trim((string) $account->naver_pw) === '') {
            return response()->json(['ok' => false, 'message' => '네이버 아이디/비밀번호가 없습니다. [수정]에서 광고주 계정을 등록하세요.'], 422);
        }

        @set_time_limit(300); // 자동 로그인(최대 ~30초) + 통계·리뷰·스마트콜·예약 수집 — 수십 초 소요
        $period = ($data['start'] ?? '') !== '' && ($data['end'] ?? '') !== '' ? [$data['start'], $data['end']] : null;

        // 1) 저장된 세션 쿠키로 우선 수집(빠름 — 매번 로그인하지 않음)
        $result = null;
        if (trim((string) $account->cookie) !== '') {
            $result = $collector->collect($account->cookie, $account->place_seq, (string) $account->category, $period);
        }

        // 2) 세션 없음/만료(loggedIn=false) → 자동 로그인 후 재수집
        if (! $result || empty($result['loggedIn'])) {
            $login = $loginService->login($account);
            if (empty($login['ok'])) {
                $account->forceFill(['last_status' => 'FAIL'])->save();

                return response()->json(['ok' => false, 'message' => '자동 로그인 실패 — '.$login['reason']], 422);
            }
            $account->refresh();
            $result = $collector->collect($account->cookie, $account->place_seq, (string) $account->category, $period);
        }

        if (empty($result['loggedIn'])) {
            $account->forceFill(['last_status' => 'FAIL'])->save();

            return response()->json([
                'ok' => false,
                'message' => '자동 로그인은 됐지만 수집이 실패했습니다. 스마트플레이스 접근 권한 또는 URL(placeSeq)을 확인하세요.',
            ], 422);
        }

        $account->applyResult($result);

        return response()->json([
            'ok' => true,
            'loggedIn' => (bool) $result['loggedIn'],
            'bearerOk' => (bool) $result['bearerOk'],
            'name' => $result['name'],
            'summary' => $summary = $this->summarize($result),
            'message' => '수집 완료 — '.$summary,
        ]);
    }

    /** 수집 리포트 (5탭) — 가로 전체 폭. */
    public function report(Request $request, SmartplaceAccount $account)
    {
        abort_unless($account->user_id === $request->user()->id, 403);
        $result = $account->last_result;

        return view('console.smartplace.report', [
            'account' => $account,
            'result' => is_array($result) ? $result : null,
            'tabs' => is_array($result) ? SmartplaceReportPresenter::tabs($result) : [],
        ]);
    }

    public function destroy(Request $request, SmartplaceAccount $account)
    {
        abort_unless($account->user_id === $request->user()->id, 403);
        $account->delete();

        return back()->with('status', '계정을 삭제했습니다.');
    }

    /** 수집 요약 — crm 과 동일 형식 (통계 n/6 · 리뷰 · 스마트콜 · 예약). */
    private function summarize(array $result): string
    {
        $s = $result['sections'] ?? [];
        $sc = 0;
        foreach (($s['stats'] ?? []) as $v) {
            if (is_array($v['data'] ?? null)) {
                $sc++;
            }
        }
        $summary = "통계 {$sc}/6";
        if (isset($s['review_visitor']['data']['data']['reviews']['totalCount'])) {
            $summary .= ' · 리뷰 '.$s['review_visitor']['data']['data']['reviews']['totalCount'];
        }
        if (isset($s['smartcall_count']['data']['total'])) {
            $summary .= ' · 스마트콜 '.$s['smartcall_count']['data']['total'];
        }
        if (isset($s['booking_users']['data']['businessUserCount'])) {
            $summary .= ' · 예약 '.$s['booking_users']['data']['businessUserCount'];
        }

        return $summary;
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'naver_id' => ['required', 'string', 'max:100'],
            'naver_pw' => [$creating ? 'required' : 'nullable', 'string', 'max:200'],
            'place_seq' => ['required', 'string', 'regex:/^\d+$/', 'max:30'],
            'business_id' => ['nullable', 'string', 'max:30'],
            'place' => ['nullable', 'string', 'max:500'], // 플레이스(지도/순위) URL — 선택
            'label' => ['required', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:50'],
        ], [
            'naver_id.required' => '네이버 아이디를 입력하세요.',
            'naver_pw.required' => '네이버 비밀번호를 입력하세요.',
            'place_seq.required' => '[매장 불러오기]로 스마트플레이스 매장을 선택하세요.',
            'label.required' => '업체명을 입력하세요.',
        ]);
    }
}
