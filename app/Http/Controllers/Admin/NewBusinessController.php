<?php

namespace App\Http\Controllers\Admin;

use App\Domain\NewBiz\NewBusinessCollector;
use App\Domain\NewBiz\NewBusinessPlaceMatcher;
use App\Http\Controllers\Controller;
use App\Models\NewBusiness;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 신규 개업(관리자) — 지방행정 인허가 공공데이터 열람 + 네이버 플레이스 등록 여부(24_NEW_BUSINESS).
 * 필터: 기간 · 시/도 › 시/군/구 · 업종 · 플레이스 유무 · 검색.
 *
 * ⚠️ 여기서 문자·이메일 발송 기능을 만들지 말 것 — 공공데이터로 얻은 연락처에 동의 없이 광고를 보내면
 *    정보통신망법 제50조 위반(수신자가 사업자여도 면제 없음, 과태료 최대 3,000만원).
 * ⚠️ 개인사업자 연락처는 개인정보일 수 있어 전화는 암호화 저장하고 열람 로그를 남긴다.
 */
class NewBusinessController extends Controller
{
    public function index(Request $request)
    {
        // 기본은 전체(0 = 기간 제한 없음, 2026-07-24 사용자 요청) — 기간 선택 시에만 좁힌다
        $days = (int) $request->query('days', 0);
        $days = in_array($days, [0, 7, 14, 30, 90], true) ? $days : 0;
        $sido = trim((string) $request->query('sido', ''));
        $sgg = trim((string) $request->query('sgg', ''));
        $svc = trim((string) $request->query('svc', ''));
        $place = trim((string) $request->query('place', ''));   // found|not_found|pending
        $q = trim((string) $request->query('q', ''));

        $base = fn () => NewBusiness::open()
            ->when($days > 0, fn ($x) => $x->whereDate('apv_perm_ymd', '>=', now()->subDays($days)->toDateString()))
            ->when($sido !== '', fn ($x) => $x->where('sido', $sido))
            ->when($sgg !== '', fn ($x) => $x->where('sgg', $sgg))
            ->when($svc !== '', fn ($x) => $x->where('svc', $svc))
            ->when(in_array($place, NewBusiness::PLACE_STATUSES, true), fn ($x) => $x->where('place_status', $place))
            ->when($q !== '', fn ($x) => $x->where('bplc_nm', 'like', '%'.addcslashes($q, '\\%_').'%'));

        // 열람 로그 — 누가 언제 어떤 조건으로 봤는지(개인정보 접근 이력)
        Log::info('newbiz: admin viewed', [
            'user' => $request->user()?->id, 'days' => $days, 'sido' => $sido, 'sgg' => $sgg, 'svc' => $svc, 'q' => $q,
        ]);

        return view('admin.new-businesses', [
            'days' => $days, 'sido' => $sido, 'sgg' => $sgg, 'svc' => $svc, 'place' => $place, 'q' => $q,
            'services' => (array) config('rankfree.newbiz.services', []),
            'sidos' => $base()->whereNotNull('sido')->selectRaw('sido, count(*) c')->groupBy('sido')->orderByDesc('c')->pluck('c', 'sido'),
            'sggs' => $sido !== ''
                ? NewBusiness::open()
                    ->when($days > 0, fn ($x) => $x->whereDate('apv_perm_ymd', '>=', now()->subDays($days)->toDateString()))
                    ->where('sido', $sido)->whereNotNull('sgg')
                    ->selectRaw('sgg, count(*) c')->groupBy('sgg')->orderByDesc('c')->pluck('c', 'sgg')
                : collect(),
            'placeCounts' => $base()->selectRaw('place_status, count(*) c')->groupBy('place_status')->pluck('c', 'place_status'),
            'total' => $base()->count(),
            'items' => $base()->orderByDesc('apv_perm_ymd')->orderByDesc('id')->paginate(50)->withQueryString(),
            'sampleKey' => app(\App\Domain\NewBiz\SeoulLocalDataClient::class)->isSampleKey(),
            // 확인 대상 = 미확인 + 재확인할 때가 된 미등록(화면 필터와 무관하게 전체 기준)
            'needCheck' => NewBusiness::open()->needsPlaceCheck()->count(),
            // 전체 재확인 대상 = 주기와 무관하게 지금 다시 볼 수 있는 미확인·미등록 전부
            'recheckable' => NewBusiness::open()->whereIn('place_status', ['pending', 'not_found', 'blocked'])->count(),
            'recheckDays' => (int) config('rankfree.newbiz.recheck_after_days', 3),
        ]);
    }

    /**
     * 지금 수집 — 최근 며칠치를 API 로 받아 적재한다. 수집이 끝나면 **이어서 플레이스 확인까지** 간다(한 흐름).
     * 화면은 fetch 로 부르고(JSON) 곧바로 placeMatch 배치를 대상이 0 이 될 때까지 반복한다 — 건수 제한 없음.
     */
    public function collect(Request $request, NewBusinessCollector $collector, NewBusinessPlaceMatcher $matcher)
    {
        $days = min(max((int) $request->input('days', 3), 1), 7);
        $created = $updated = 0;
        $errors = [];
        foreach ((array) config('rankfree.newbiz.services', []) as $svc => $label) {
            for ($i = 0; $i < $days; $i++) {
                // 공공데이터는 D-2 기준 현행화 — 오늘·어제는 비어 있는 게 정상
                $r = $collector->collectDate($svc, $label, now()->subDays($i + 2));
                $created += $r['created'];
                $updated += $r['updated'];
                if ($r['error']) {
                    $errors[] = "{$label}: {$r['error']}";
                }
            }
        }
        $errors = array_values(array_unique($errors));
        $remaining = NewBusiness::open()->needsPlaceCheck()->count();

        if ($request->expectsJson()) {
            return response()->json([
                'created' => $created, 'updated' => $updated, 'remaining' => $remaining,
                'errors' => $errors, 'sample' => app(\App\Domain\NewBiz\SeoulLocalDataClient::class)->isSampleKey(),
            ]);
        }

        // JS 가 없을 때의 폴백 — 한 요청에서 배치 한 번만 확인하고 나머지는 안내(타임아웃 방지)
        $stat = $this->matchBatch($matcher);

        return back()->with('status', "수집 완료 — 신규 {$created}건 · 갱신 {$updated}건"
            .($stat['done'] ? " · 플레이스 확인 {$stat['done']}건(있음 {$stat['found']} · 미등록 {$stat['not_found']})" : '')
            .($stat['remaining'] ? " · {$stat['remaining']}건 남음('플레이스 확인'으로 이어서)" : '')
            .($errors ? ' · 오류: '.implode(' / ', $errors) : '')
            .(app(\App\Domain\NewBiz\SeoulLocalDataClient::class)->isSampleKey() ? ' (인증키가 sample 이라 일자당 5건 제한 — 환경 설정 > 연동에서 키 입력)' : ''));
    }

    /**
     * 플레이스 확인 — **건수 제한 없이 대상이 0 이 될 때까지** 확인한다.
     * 한 요청에서 전부 돌리면 타임아웃이라 요청당 배치(place_match_batch)만 처리하고 남은 수를 돌려주면,
     * 화면이 0 이 될 때까지 이어서 부른다(진행률 표시).
     *
     * 대상: 기본 = 미확인 + 재확인 주기가 된 미등록(scopeNeedsPlaceCheck).
     *       force=1 = **주기를 무시하고 미등록 전부 지금 다시 확인**(관리자가 직접 누른 경우).
     */
    public function placeMatch(Request $request, NewBusinessPlaceMatcher $matcher)
    {
        $force = $request->boolean('force');
        // 이번 재확인 시작 시각 — 방금 확인한 건은 place_checked_at 이 이 시각 이후가 되어 대상에서 빠진다(루프 종료 보장)
        $since = $force ? ($request->input('since') ?: now()->toDateTimeString()) : null;
        $stat = $this->matchBatch($matcher, $since);

        if ($request->expectsJson()) {
            return response()->json($stat + ['since' => $since, 'keys' => (bool) config('rankfree.shopping.api_keys')]);
        }
        if (! $stat['done']) {
            return back()->with('status', '확인할 업소가 없습니다.');
        }

        return back()->with('status', "플레이스 확인 — 있음 {$stat['found']} · 없음(미등록) {$stat['not_found']}"
            .($stat['remaining'] ? " · {$stat['remaining']}건 남음" : '')
            .(config('rankfree.shopping.api_keys') ? '' : ' ⚠️ 네이버 지역검색 키가 없어 조회가 되지 않습니다'));
    }

    /** 확인 대상 한 배치를 목록과 같은 순서(최신 인허가부터)로 처리하고 남은 수를 함께 돌려준다. */
    private function matchBatch(NewBusinessPlaceMatcher $matcher, ?string $since = null): array
    {
        $target = fn () => NewBusiness::open()->placeCheckTarget($since);
        $rows = $target()->orderByDesc('apv_perm_ymd')->orderByDesc('id')
            ->limit(max(1, (int) config('rankfree.newbiz.place_match_batch', 5)))->get();

        $stat = ['done' => $rows->count(), 'found' => 0, 'not_found' => 0];
        foreach ($rows as $i => $biz) {
            if ($i > 0) {
                usleep(300_000);   // 공식 지역검색 API 호출 간격
            }
            $stat[$matcher->match($biz)]++;
        }
        $stat['remaining'] = $target()->count();

        return $stat;
    }
}
