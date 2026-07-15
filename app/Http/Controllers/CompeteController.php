<?php

namespace App\Http\Controllers;

use App\Domain\Place\PlaceCalibration;
use App\Domain\Place\PlaceScorer;
use App\Domain\Place\PlaceSeoAnalyzer;
use App\Models\PlaceRankSlot;
use App\Models\PlaceSeoDaily;
use App\Models\PlaceSeoScore;
use App\Models\PlaceSeoSerp;
use App\Models\SmartplaceAccount;
use Illuminate\Http\Request;

/** 플레이스 경쟁 분석(SEO 점수 + 순위추적) — 웹 콘솔. 로직은 PlaceSeoAnalyzer 공유. */
class CompeteController extends Controller
{
    /** 트랙(=순위추적 슬롯) 목록 + 최신 N1/N2/N3 + 전일대비 델타 + N3 추이. */
    public function index(Request $request)
    {
        $user = $request->user();
        $q = trim((string) $request->query('q', ''));
        $slots = $user->rankSlots()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('keyword', 'like', "%{$q}%")->orWhere('place_name', 'like', "%{$q}%")))
            ->latest()->get();

        // 슬롯별 내 매장 점수 시계열(델타·스파크라인용)
        $mineScores = PlaceSeoScore::whereIn('slot_id', $slots->pluck('id'))
            ->where('is_mine', true)
            ->orderBy('ymd')
            ->get()
            ->groupBy('slot_id');

        return view('console.compete.index', [
            'slots' => $slots,
            'mineScores' => $mineScores,
            'usedSlots' => $user->rankSlotsUsedTotal(),
            'maxSlots' => $user->rankSlotLimit(),
            'q' => $q,
        ]);
    }

    /** 분석 실행(경쟁셋 수집 + 점수 저장). AJAX 면 JSON(로딩 안내용). */
    public function analyze(Request $request, PlaceRankSlot $slot, PlaceSeoAnalyzer $analyzer)
    {
        abort_unless($slot->user_id === $request->user()->id, 403);

        if (! $request->user()->tryConsumeFeature('compete_analysis')) {
            $msg = '이번 달 경쟁 분석 횟수('.$request->user()->featureLimit('compete_analysis').'회)를 모두 사용했습니다. 요금제를 업그레이드하세요.';
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['ok' => false, 'limit_exceeded' => true, 'message' => $msg], 429);
            }

            return back()->with('status', $msg);
        }

        @set_time_limit(300); // 경쟁셋 상세 + 리뷰 수집은 수십 초 소요(동기)

        $res = $analyzer->analyze($slot, 10);
        $blocked = $res['blocked'];
        $msg = $blocked
            ? '조회가 일시적으로 제한됐습니다 (nCaptcha 토큰 재발급 필요).'
            : "분석 완료 · 내 순위 {$res['my_rank']}위 · 경쟁 {$res['competitors']}개";

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['ok' => ! $blocked, 'message' => $msg, 'redirect' => route('console.compete.show', $slot)]);
        }
        if ($blocked) {
            return back()->with('status', $msg);
        }

        return redirect()->route('console.compete.show', $slot)->with('status', $msg);
    }

    /** 경쟁 비교 상세. */
    public function show(Request $request, PlaceRankSlot $slot)
    {
        abort_unless($slot->user_id === $request->user()->id, 403);

        $ymd = PlaceSeoScore::where('slot_id', $slot->id)->max('ymd');
        $ymd = $ymd instanceof \Illuminate\Support\Carbon ? $ymd->toDateString() : $ymd;
        $data = $ymd ? $this->buildComparison($slot, $ymd) : ['rows' => collect(), 'mine' => null, 'explain' => null, 'dates' => collect()];

        $series = $ymd
            ? PlaceSeoScore::where('slot_id', $slot->id)->where('place_id', $slot->place_id)
                ->orderBy('ymd')->get(['ymd', 'n1', 'n2', 'n3', 'rnk'])
            : collect();

        return view('console.compete.show', [
            'slot' => $slot,
            'ymd' => $ymd,
            'dates' => $data['dates'],
            'rows' => $data['rows'],
            'mine' => $data['mine'],
            'explain' => $data['explain'],
            'prevYmd' => $data['prevYmd'] ?? null,
            'series' => $series,
            'calibration' => $this->buildCalibration($request->user()->id, $slot, $series),
        ]);
    }

    /**
     * 실측 캘리브레이션 — 내 매장 스마트플레이스 조회수(일자별 PV) ↔ N2/순위 추정치 비교.
     * 연결 키: smartplace_accounts.place_id == slot.place_id (동일 m.place placeId).
     * 사적 데이터(조회수)이므로 인증 소유자의 show() 에서만 조립하고 공개 공유엔 넘기지 않는다.
     */
    private function buildCalibration(int $userId, PlaceRankSlot $slot, \Illuminate\Support\Collection $series): array
    {
        $pid = preg_replace('/\D/', '', (string) $slot->place_id);
        $acct = $pid !== ''
            ? SmartplaceAccount::where('user_id', $userId)->where('place_id', $pid)->first()
            : null;
        if (! $acct) {
            return ['state' => 'unlinked'];
        }
        $pv = PlaceCalibration::dailyPv(is_array($acct->last_result) ? $acct->last_result : null);
        $label = $acct->label ?: ($acct->sp_name ?: $slot->place_name);
        if (! $pv) {
            return ['state' => 'no_pv', 'label' => $label];
        }
        $period = $acct->last_result['period'] ?? null;

        return ['state' => 'linked', 'label' => $label, 'period' => $period,
            'collected_at' => optional($acct->last_collected_at)->toDateString()]
            + PlaceCalibration::summarize($pv, $series);
    }

    /** slot+ymd → 일별순위 매트릭스 + 비교 행 + 내 매장 explain. */
    public function buildComparison(PlaceRankSlot $slot, string $ymd): array
    {
        // 최근 10일(일별 순위 컬럼, 최신순)
        $dates = PlaceSeoSerp::where('slot_id', $slot->id)
            ->select('ymd')->distinct()->orderByDesc('ymd')->limit(10)->pluck('ymd')
            ->map(fn ($d) => $d instanceof \Illuminate\Support\Carbon ? $d->toDateString() : (string) $d)
            ->values();

        // 일별 순위 매트릭스: place_id => [ymd => rnk]
        $matrix = PlaceSeoSerp::where('slot_id', $slot->id)->whereIn('ymd', $dates->all())->get()
            ->groupBy('place_id')
            ->map(function ($g) {
                $m = [];
                foreach ($g as $r) {
                    $m[$r->ymd instanceof \Illuminate\Support\Carbon ? $r->ymd->toDateString() : (string) $r->ymd] = $r->rnk;
                }

                return $m;
            });

        $scores = PlaceSeoScore::where('slot_id', $slot->id)->where('ymd', $ymd)->get()->keyBy('place_id');
        $dailies = PlaceSeoDaily::where('ymd', $ymd)->whereIn('place_id', $scores->keys())->get()->keyBy('place_id');

        // 직전 분석일($dates[1]) 스냅샷 — 지표 변화량(델타) 계산용
        $prevYmd = $dates[1] ?? null;
        $prevScores = $prevYmd ? PlaceSeoScore::where('slot_id', $slot->id)->where('ymd', $prevYmd)->get()->keyBy('place_id') : collect();
        $prevSerp = $prevYmd ? PlaceSeoSerp::where('slot_id', $slot->id)->where('ymd', $prevYmd)->get()->keyBy('place_id') : collect();

        $rows = PlaceSeoSerp::where('slot_id', $slot->id)->where('ymd', $ymd)
            ->orderByDesc('is_mine')->orderBy('rnk')->get()
            ->map(function ($s) use ($scores, $dailies, $matrix, $prevScores, $prevSerp) {
                $sc = $scores[$s->place_id] ?? null;
                $d = $dailies[$s->place_id] ?? null;
                $psc = $prevScores[$s->place_id] ?? null;
                $pse = $prevSerp[$s->place_id] ?? null;
                $wv = $wb = null;
                if ($d && is_array($d->review_weekly)) {
                    $wv = $this->weekInc($d->review_weekly['v'] ?? null);
                    $wb = $this->weekInc($d->review_weekly['b'] ?? null);
                }

                return (object) [
                    'rnk' => $s->rnk, 'name' => $s->name, 'place_id' => $s->place_id, 'is_mine' => $s->is_mine, 'address' => $s->address,
                    'daily' => $matrix[$s->place_id] ?? [],
                    'visitor' => $s->visitor_cnt, 'blog' => $s->blog_cnt, 'score' => $s->review_score,
                    'wv' => $wv, 'wb' => $wb,
                    'd7' => $sc?->d7, 'n1' => $sc?->n1, 'n2' => $sc?->n2, 'n3' => $sc?->n3, 'tier' => $sc?->tier,
                    // 직전 분석일 대비 델타용 prev 값
                    'rnk_prev' => $pse?->rnk, 'visitor_prev' => $pse?->visitor_cnt, 'blog_prev' => $pse?->blog_cnt, 'score_prev' => $pse?->review_score,
                    'n1_prev' => $psc?->n1, 'n2_prev' => $psc?->n2, 'n3_prev' => $psc?->n3,
                ];
            });

        $mine = $rows->firstWhere('is_mine', true);

        $explain = null;
        $mineDaily = $dailies[$slot->place_id] ?? null;
        $mineScore = $scores[$slot->place_id] ?? null;
        if ($mine && $mineDaily) {
            $cat = $slot->category ?: 'place';
            $explain = [
                'components' => PlaceScorer::keywordComponents($slot->keyword, $mineDaily->name, (string) $mineDaily->category, (string) $mine->address, $mineDaily->tags ?? [], $cat),
                'seo' => PlaceScorer::seoItems($mineDaily->toArray(), $cat),
                'dims' => $mineScore,
                'daily' => $mineDaily,
            ];
        }

        return ['dates' => $dates, 'rows' => $rows, 'mine' => $mine, 'explain' => $explain, 'prevYmd' => $prevYmd];
    }

    /** 누적 4주 버킷 → 주별 신규 증가 [1주,2주,3주,4주]. */
    private function weekInc($cum): ?array
    {
        if (! is_array($cum) || count($cum) < 4) {
            return null;
        }

        return [(int) $cum[0], (int) $cum[1] - (int) $cum[0], (int) $cum[2] - (int) $cum[1], (int) $cum[3] - (int) $cum[2]];
    }

    /** 특정 매장 점수 근거(상세) — crm _explain_render 형식 HTML 반환. */
    public function explain(Request $request, PlaceRankSlot $slot, string $place)
    {
        abort_unless($slot->user_id === $request->user()->id, 403);
        $place = preg_replace('/\D/', '', $place);
        $ymd = PlaceSeoScore::where('slot_id', $slot->id)->where('place_id', $place)->max('ymd');
        $ymd = $ymd instanceof \Illuminate\Support\Carbon ? $ymd->toDateString() : $ymd;
        if (! $ymd) {
            return response()->json(['ok' => false]);
        }
        $sc = PlaceSeoScore::where('slot_id', $slot->id)->where('place_id', $place)->where('ymd', $ymd)->first();
        $serp = PlaceSeoSerp::where('slot_id', $slot->id)->where('ymd', $ymd)->where('place_id', $place)->first();
        $daily = PlaceSeoDaily::where('place_id', $place)->where('ymd', $ymd)->first();
        $cat = $slot->category ?: 'place';
        $rnk = (int) ($sc?->rnk ?? 300);

        $x = [
            'name' => $serp?->name ?: ($daily?->name ?: ''),
            'category' => (string) ($daily?->category ?? ''),
            'is_mine' => $place === preg_replace('/\D/', '', (string) $slot->place_id),
            'place_plus' => $daily?->place_plus,
            'keyword' => $slot->keyword,
            'rnk' => $rnk,
            'ymd' => $ymd,
            'rep_keywords' => is_array($daily?->tags) ? $daily->tags : [],
            'n1' => $sc?->n1, 'n2' => $sc?->n2, 'n3' => $sc?->n3,
            'n3formula' => '100 × (1 − ln('.min($rnk, 300).') ÷ ln 301)',
            'kc' => $daily
                ? PlaceScorer::keywordComponents($slot->keyword, $daily->name, (string) $daily->category, (string) ($serp?->address ?? ''), $daily->tags ?? [], $cat)
                : ['L' => null, 'B' => null, 'T' => null, 'M' => null, 'region' => '', 'core' => '', 'bizterm' => ''],
            'd7' => $sc?->d7,
            'n2parts' => [
                ['label' => '방문자 리뷰', 'code' => 'D1', 'w' => 0.18, 'v' => $sc?->d1],
                ['label' => '블로그 리뷰', 'code' => 'D2', 'w' => 0.09, 'v' => $sc?->d2],
                ['label' => '예약자 리뷰', 'code' => 'D3', 'w' => 0.07, 'v' => $sc?->d3],
                ['label' => '평점', 'code' => 'D4', 'w' => 0.12, 'v' => $sc?->d4],
                ['label' => '저장수', 'code' => 'D5', 'w' => 0.08, 'v' => $sc?->d5],
                ['label' => '정보충실성', 'code' => 'D7', 'w' => 0.14, 'v' => $sc?->d7],
                ['label' => '최근 활동', 'code' => 'D9', 'w' => 0.20, 'v' => $sc?->d9],
                ['label' => '리뷰 영향력', 'code' => 'D10', 'w' => 0.12, 'v' => $sc?->d10],
            ],
            'seo' => $daily ? PlaceScorer::seoItems($daily->toArray(), $cat) : [],
            'review_kw' => $daily?->review_kw,
            'review_quality' => $daily?->review_quality,
        ];

        // 블로그 리뷰어 품질 분석(캐시 168h) — review_quality.bloggers
        $rq = is_array($daily?->review_quality) ? $daily->review_quality : [];
        if (! empty($rq['bloggers']) && is_array($rq['bloggers'])) {
            @set_time_limit(120);
            $ids = array_values(array_filter(array_map(fn ($b) => $b['id'] ?? '', $rq['bloggers'])));
            $x['bloggers_analyzed'] = app(\App\Domain\Place\BlogAnalyzer::class)->analyzeMany($ids, 168, 5);
        }

        return response()->json(['ok' => true, 'html' => view('compete._explain', ['x' => $x])->render()]);
    }

    /** 공개 공유 리포트(로그인 불필요) — share_token 으로 열람. */
    public function shared(string $token)
    {
        $slot = PlaceRankSlot::where('share_token', $token)->firstOrFail();
        $ymd = PlaceSeoScore::where('slot_id', $slot->id)->max('ymd');
        $ymd = $ymd instanceof \Illuminate\Support\Carbon ? $ymd->toDateString() : $ymd;
        $data = $ymd ? $this->buildComparison($slot, $ymd) : ['rows' => collect(), 'mine' => null, 'explain' => null, 'dates' => collect()];

        return view('compete.share', [
            'slot' => $slot,
            'ymd' => $ymd,
            'dates' => $data['dates'],
            'rows' => $data['rows'],
            'mine' => $data['mine'],
            'explain' => $data['explain'],
        ]);
    }

    /** 특정 매장 순위·점수 추이 — crm _history_render 형식 HTML 반환. */
    public function history(Request $request, PlaceRankSlot $slot, string $place)
    {
        abort_unless($slot->user_id === $request->user()->id, 403);
        $place = preg_replace('/\D/', '', $place);
        $toYmd = fn ($v) => $v instanceof \Illuminate\Support\Carbon ? $v->toDateString() : (string) $v;

        $serp = PlaceSeoSerp::where('slot_id', $slot->id)->where('place_id', $place)->orderBy('ymd')->get();
        $scores = PlaceSeoScore::where('slot_id', $slot->id)->where('place_id', $place)->get()->keyBy(fn ($r) => $toYmd($r->ymd));
        $dailies = PlaceSeoDaily::where('place_id', $place)->get()->keyBy(fn ($r) => $toYmd($r->ymd));

        $rows = $serp->map(function ($s) use ($scores, $dailies, $toYmd) {
            $ymd = $toYmd($s->ymd);
            $sc = $scores[$ymd] ?? null;
            $pd = $dailies[$ymd] ?? null;

            return [
                'ymd' => $ymd, 'rnk' => $s->rnk, 'visitor_cnt' => $s->visitor_cnt, 'blog_cnt' => $s->blog_cnt,
                'save_cnt' => $s->save_cnt, 'review_score' => $s->review_score ?? $pd?->review_score,
                'd7' => $sc?->d7, 'd9' => $sc?->d9, 'd10' => $sc?->d10,
                'n1' => $sc?->n1, 'n2' => $sc?->n2, 'n3' => $sc?->n3, 'place_plus' => $pd?->place_plus,
            ];
        })->all();

        return response()->json(['ok' => true, 'html' => view('compete._history', ['rows' => $rows, 'name' => $serp->last()?->name])->render()]);
    }
}
