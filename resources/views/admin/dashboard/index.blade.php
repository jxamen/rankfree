@extends('admin.layout')
@section('page-title', '대시보드')
@use('Illuminate\Support\Carbon')

@php
    $fmt = fn ($n) => number_format((int) $n);
    $kst = fn ($v) => $v ? Carbon::parse($v)->timezone('Asia/Seoul')->format('m-d H:i') : '';
@endphp

@push('head')
<style>
    .dash-kpis { display:grid; gap:14px; grid-template-columns:repeat(2,1fr); }
    @media(min-width:680px){ .dash-kpis{ grid-template-columns:repeat(3,1fr); } }
    @media(min-width:1120px){ .dash-kpis{ grid-template-columns:repeat(4,1fr); } }
    .dash-kpi { display:block; }
    .dash-kpi .lab { color:var(--color-muted); font-size:var(--fs-xs); font-weight:500; }
    .dash-kpi .val { font-family:var(--font-mono); font-size:var(--fs-2xl); font-weight:650; line-height:1.05; margin-top:8px; letter-spacing:-.02em; color:var(--color-ink); }
    .dash-kpi .sub { color:var(--color-muted-soft); font-size:var(--fs-xs); margin-top:8px; }
    .dash-kpi .sub b { color:var(--color-success); font-weight:600; }
    .dash-kpi .sub b.warn { color:var(--color-error); }
    .dash-cols { display:grid; gap:14px; grid-template-columns:1fr; margin-top:14px; }
    @media(min-width:1024px){ .dash-cols{ grid-template-columns:2fr 1fr; } }
    .dash-recent { display:grid; gap:14px; grid-template-columns:1fr; margin-top:14px; }
    @media(min-width:1024px){ .dash-recent{ grid-template-columns:1fr 1fr; } }
    .dash-h { font-size:var(--fs-sm); font-weight:650; margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; gap:8px; }
    .dash-h a { color:var(--color-primary); font-size:var(--fs-xs); font-weight:500; text-decoration:none; }
    .dash-h a:hover { text-decoration:underline; }
    .dash-tbl { width:100%; border-collapse:collapse; font-size:var(--fs-xs); }
    .dash-tbl td { padding:9px 4px; border-top:1px solid var(--color-hairline-soft); vertical-align:middle; }
    .dash-tbl tr:first-child td { border-top:0; }
    .dash-tbl .r { text-align:right; color:var(--color-muted); font-family:var(--font-mono); white-space:nowrap; }
    .dash-tbl .t { color:var(--color-ink); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:1px; width:100%; }
    .dash-tbl .m { color:var(--color-muted); white-space:nowrap; }
    .dash-empty { color:var(--color-muted-soft); font-size:var(--fs-xs); text-align:center; padding:20px; }
    .dash-chip { display:inline-block; font-size:var(--fs-xs); padding:1px 8px; border-radius:99px; background:var(--color-surface-strong); color:var(--color-muted); white-space:nowrap; }
    .dash-chip.ok { background:color-mix(in srgb,var(--color-success) 14%,var(--color-canvas)); color:var(--color-success); }
    .dash-chip.warn { background:color-mix(in srgb,var(--color-error) 12%,var(--color-canvas)); color:var(--color-error); }
    .dash-chart { display:flex; align-items:flex-end; gap:2px; height:120px; }
    .dash-chart>div { flex:1; min-width:2px; background:linear-gradient(var(--color-primary),color-mix(in srgb,var(--color-primary) 55%,var(--color-canvas))); border-radius:3px 3px 0 0; }
    .dash-axis { display:flex; justify-content:space-between; color:var(--color-muted-soft); font-size:var(--fs-xs); margin-top:6px; }
    .dash-mini { display:flex; gap:18px; flex-wrap:wrap; }
    .dash-mini .n { font-family:var(--font-mono); font-size:var(--fs-lg); font-weight:650; color:var(--color-ink); }
    .dash-mini .k { color:var(--color-muted); font-size:var(--fs-xs); }
</style>
@endpush

@section('admin-content')
<x-console.page-head title="대시보드" desc="방문·가입·순위추적·문의·커뮤니티·키워드 발행 현황을 한눈에" />

{{-- ── 핵심 지표 ───────────────────────────────────── --}}
<div class="dash-kpis">
    @php
        $cards = [
            ['총 회원', $kpi['users'], '오늘 +'.$fmt($kpi['usersToday']).' · 7일 +'.$fmt($kpi['users7']), route('admin.members')],
            ['유료 구독중', $kpi['paid'], '전체의 '.($kpi['users'] ? round($kpi['paid'] / $kpi['users'] * 100, 1) : 0).'%', route('admin.subscriptions')],
            ['플레이스 추적', $kpi['placeSlots'], '활성 '.$fmt($kpi['placeActive']).'개', route('admin.place-tracking')],
            ['쇼핑 추적', $kpi['shopSlots'], '활성 '.$fmt($kpi['shopActive']).'개', route('admin.shop-tracking')],
            ['플레이스 발행', $kpi['hubPlace'], '7일 +'.$fmt($kpi['hubPlace7']).'건', route('admin.keyword-hub')],
            ['쇼핑 발행', $kpi['hubShopping'], '7일 +'.$fmt($kpi['hubShopping7']).'건', route('admin.keyword-hub')],
            ['커뮤니티 글', $kpi['posts'], '실사용자 '.$fmt($kpi['postsUser']).' · 7일 +'.$fmt($kpi['posts7']), route('community')],
            ['미답변 문의', $kpi['qnaOpen'], '전체 '.$fmt($kpi['qnaTotal']).'건', route('admin.qnas'), $kpi['qnaOpen'] > 0],
            ['주문', $kpi['orders'], '접수대기 '.$fmt($kpi['ordersPending']).'건', route('admin.orders'), $kpi['ordersPending'] > 0],
        ];
    @endphp
    @foreach ($cards as $c)
        @php $href = $c[3] ?? null; $warn = $c[4] ?? false; @endphp
        <{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" @endif class="card p-5 dash-kpi" style="text-decoration:none;">
            <div class="lab">{{ $c[0] }}</div>
            <div class="val">{{ $fmt($c[1]) }}</div>
            <div class="sub">@if ($warn)<b class="warn">{{ $c[2] }}</b>@else{{ $c[2] }}@endif</div>
        </{{ $href ? 'a' : 'div' }}>
    @endforeach
</div>

{{-- ── 가입 추이 + 사이드(방문·허브) ─────────────────── --}}
<div class="dash-cols">
    <div class="card p-5">
        <div class="dash-h"><span>가입 추이 <span class="text-muted-soft" style="font-weight:400;font-size:var(--fs-xs);">최근 30일</span></span></div>
        @php $mx = max(1, collect($signupTrend)->max('count') ?: 1); @endphp
        <div class="dash-chart">
            @foreach ($signupTrend as $t)
                <div title="{{ $t['date'] }} · 가입 {{ $t['count'] }}명" style="height:{{ max(2, round($t['count'] / $mx * 100)) }}%"></div>
            @endforeach
        </div>
        <div class="dash-axis"><span>{{ $signupTrend[0]['date'] ?? '' }}</span><span>{{ $signupTrend[count($signupTrend) - 1]['date'] ?? '' }}</span></div>
    </div>

    <div>
        {{-- 방문(GA4) --}}
        <div class="card p-5">
            <div class="dash-h"><span>방문 <span class="text-muted-soft" style="font-weight:400;font-size:var(--fs-xs);">최근 7일</span></span><a href="{{ route('admin.traffic-stats') }}">상세 →</a></div>
            @if ($visits['has'])
                <div class="dash-mini">
                    <div><div class="n">{{ $fmt($visits['users']) }}</div><div class="k">사용자</div></div>
                    <div><div class="n">{{ $fmt($visits['sessions']) }}</div><div class="k">세션</div></div>
                    <div><div class="n">{{ $fmt($visits['pageviews']) }}</div><div class="k">페이지뷰</div></div>
                </div>
                <div class="text-muted-soft" style="font-size:var(--fs-xs);margin-top:10px;">GA4 수집분 · 갱신 {{ $kst($visits['lastAt']) }}</div>
            @else
                <div class="dash-empty">GA4 방문 데이터가 아직 없습니다. <a href="{{ route('admin.traffic-stats') }}" style="color:var(--color-primary);">방문 분석 열기 →</a></div>
            @endif
        </div>

        {{-- 키워드 콘텐츠 허브 --}}
        <div class="card p-5" style="margin-top:14px;">
            <div class="dash-h"><span>키워드 콘텐츠 허브</span><a href="{{ route('admin.keyword-hub') }}">관리 →</a></div>
            <div class="dash-mini">
                <div><div class="n">{{ $fmt($kpi['hubPlace']) }}</div><div class="k">플레이스 발행</div></div>
                <div><div class="n">{{ $fmt($kpi['hubShopping']) }}</div><div class="k">쇼핑 발행</div></div>
                <div><div class="n">{{ $fmt($kpi['candApproved']) }}</div><div class="k">승인 대기</div></div>
                <div><div class="n">{{ $fmt($kpi['candPending']) }}</div><div class="k">검토 후보</div></div>
            </div>
            <div class="text-muted-soft" style="font-size:var(--fs-xs);margin-top:10px;">발행 문서 {{ $fmt($kpi['hubDocs']) }}건 · 오늘 {{ $fmt($kpi['hubToday']) }}건 · 승인분은 자동 발행됩니다</div>
        </div>
    </div>
</div>

{{-- ── 최근 활동 ───────────────────────────────────── --}}
<div class="dash-recent">
    {{-- 최근 가입 --}}
    <div class="card p-5">
        <div class="dash-h"><span>최근 가입 회원</span><a href="{{ route('admin.members') }}">전체 →</a></div>
        <table class="dash-tbl">
            @forelse ($recentUsers as $u)
                <tr>
                    <td class="t">{{ $u->name }} <span class="dash-chip">{{ $u->grade?->name ?? '무료' }}</span></td>
                    <td class="m" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;">{{ $u->email }}</td>
                    <td class="r">{{ $kst($u->created_at) }}</td>
                </tr>
            @empty
                <tr><td class="dash-empty">가입 회원이 없습니다.</td></tr>
            @endforelse
        </table>
    </div>

    {{-- 최근 순위추적 등록 --}}
    <div class="card p-5">
        <div class="dash-h"><span>최근 순위추적 등록</span></div>
        <table class="dash-tbl">
            @forelse ($recentTracking as $r)
                <tr>
                    <td style="width:1px;"><span class="dash-chip {{ $r['type'] === '플레이스' ? 'ok' : '' }}">{{ $r['type'] }}</span></td>
                    <td class="t"><b class="text-ink">{{ $r['keyword'] }}</b> <span class="m">· {{ \Illuminate\Support\Str::limit($r['target'], 22) }}</span></td>
                    <td class="m">{{ $r['user'] ?? '—' }}</td>
                    <td class="r">{{ $kst($r['at']) }}</td>
                </tr>
            @empty
                <tr><td class="dash-empty">순위추적 등록이 없습니다.</td></tr>
            @endforelse
        </table>
    </div>

    {{-- 최근 1:1 문의 --}}
    <div class="card p-5">
        <div class="dash-h"><span>최근 1:1 문의</span><a href="{{ route('admin.qnas') }}">전체 →</a></div>
        <table class="dash-tbl">
            @forelse ($recentQna as $q)
                <tr>
                    <td style="width:1px;"><span class="dash-chip {{ $q->status === 'answered' ? 'ok' : 'warn' }}">{{ $q->status === 'answered' ? '답변완료' : '대기' }}</span></td>
                    <td class="t"><a href="{{ route('admin.qnas.show', $q) }}" style="color:var(--color-ink);text-decoration:none;">{{ $q->title }}</a></td>
                    <td class="m">{{ $q->user?->name ?? '—' }}</td>
                    <td class="r">{{ $kst($q->created_at) }}</td>
                </tr>
            @empty
                <tr><td class="dash-empty">문의가 없습니다.</td></tr>
            @endforelse
        </table>
    </div>

    {{-- 최근 커뮤니티 글 --}}
    <div class="card p-5">
        <div class="dash-h"><span>최근 커뮤니티 글</span><a href="{{ route('community') }}">전체 →</a></div>
        <table class="dash-tbl">
            @forelse ($recentPosts as $p)
                <tr>
                    <td style="width:1px;"><span class="dash-chip {{ $p->author_type === 'user' ? 'ok' : '' }}">{{ $p->author_type === 'user' ? '회원' : '페르소나' }}</span></td>
                    <td class="t"><a href="{{ url('/community/post/'.$p->id) }}" target="_blank" rel="noopener" style="color:var(--color-ink);text-decoration:none;">{{ $p->title }}</a></td>
                    <td class="m">{{ $p->authorName() }}</td>
                    <td class="r">{{ $kst($p->created_at) }}</td>
                </tr>
            @empty
                <tr><td class="dash-empty">글이 없습니다.</td></tr>
            @endforelse
        </table>
    </div>
</div>
@endsection
