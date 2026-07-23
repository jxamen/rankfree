@extends('console.layout')
@section('page-title', '대시보드')

@section('console-content')
    @php
        $user = auth()->user();
        $grade = $user->grade;
        $planName = $grade?->name ?? '무료';
        $isPaid = (bool) ($grade?->is_paid);
        $active = $user->subscriptionActive();
        $expires = $user->subscription_expires_at;
        $daysLeft = $expires ? (int) ceil(now()->floatDiffInDays($expires, false)) : null;
        $slotPct = $maxSlots > 0 ? min(100, round($usedSlots / $maxSlots * 100)) : 0;
    @endphp

    {{-- 홍보 배너 --}}
    @include('console.partials.banners', ['banners' => $banners])

    {{-- 구독 · 사용량 --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        {{-- 구독 플랜 --}}
        {{-- featured 다크 카드 — elevated 서피스라 다크모드 캔버스(#0a0b0d)와도 구분됨 --}}
        <div class="card p-6 flex flex-col" style="background:var(--color-surface-dark-elevated);color:#fff;">
            <div class="flex items-center justify-between">
                <span style="font-size:var(--fs-xs);opacity:.7;">현재 요금제</span>
                @if ($isPaid)
                    <span class="badge" style="font-size:var(--fs-xs);padding:2px 9px;background:{{ $active ? 'color-mix(in srgb,var(--color-success) 30%,transparent)' : 'color-mix(in srgb,var(--color-error) 30%,transparent)' }};color:#fff;border:0;">{{ $active ? '이용중' : '만료' }}</span>
                @else
                    <span class="badge" style="font-size:var(--fs-xs);padding:2px 9px;background:rgba(255,255,255,.18);color:#fff;border:0;">무료</span>
                @endif
            </div>
            <div class="font-display mt-2" style="font-size:var(--fs-2xl);line-height:1.1;">{{ $planName }}</div>
            <div style="font-size:var(--fs-xs);opacity:.75;margin-top:6px;">
                @if ($isPaid && $expires)
                    {{ $expires->format('Y-m-d') }}까지 · 남은 기간 <b>{{ max(0, $daysLeft) }}일</b>
                @elseif ($isPaid)
                    무기한 이용
                @else
                    유료 전환 시 순위 무제한·자동추적을 이용할 수 있어요
                @endif
            </div>
            <div class="mt-auto pt-4">
                @if ($isPaid)
                    <a href="{{ route('console.qna.create') }}" class="btn btn-secondary btn-sm w-full" style="background:rgba(255,255,255,.14);color:#fff;border:0;">구독 문의</a>
                @else
                    <a href="{{ route('console.developers') }}" class="btn btn-sm btn-on-dark w-full">요금제 보기 →</a>
                @endif
            </div>
        </div>

        {{-- 순위 추적 슬롯 --}}
        <div class="card p-6">
            <div class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">순위 추적 (플레이스+쇼핑)</div>
            <div class="font-display text-ink mt-2" style="font-size:var(--fs-2xl);">
                {{ number_format($usedSlots) }}<span class="text-muted-soft" style="font-size:var(--fs-lg);"> / {{ $maxSlots < 0 ? '무제한' : number_format($maxSlots) }}</span>
            </div>
            @if ($maxSlots >= 0)
                <div class="mt-3" style="height:8px;border-radius:99px;background:var(--color-surface-strong);overflow:hidden;">
                    <div style="height:100%;width:{{ $slotPct }}%;background:var(--color-primary);border-radius:99px;"></div>
                </div>
                <div class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">잔여 {{ number_format(max(0, $maxSlots - $usedSlots)) }}개</div>
            @else
                <div class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">무제한 이용 중</div>
            @endif
            <a href="{{ route('console.rank') }}" class="text-accent hover:underline mt-3 inline-block" style="font-size:var(--fs-xs);font-weight:600;">추적 관리 →</a>
        </div>

        {{-- 이번 달 조회 --}}
        <div class="card p-6">
            <div class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">이번 달 순위 조회</div>
            <div class="font-display text-ink mt-2" style="font-size:var(--fs-2xl);">{{ number_format($monthCount) }}<span class="text-muted-soft" style="font-size:var(--fs-lg);">회</span></div>
            <div class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">{{ now()->format('Y년 n월') }} 누적</div>
            <a href="/#hero-form" class="text-accent hover:underline mt-3 inline-block" style="font-size:var(--fs-xs);font-weight:600;">새 조회 →</a>
        </div>
    </div>

    {{-- 기능별 이번 달 사용량 --}}
    <div class="card p-6 mb-6">
        <div class="text-ink font-semibold mb-4" style="font-size:var(--fs-sm);">이번 달 기능별 이용량 <span class="text-muted-soft" style="font-size:var(--fs-xs);font-weight:400;">{{ $planName }} 요금제 기준</span></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
            @foreach ($features as $f)
                @php
                    $unlimited = $f['limit'] < 0;
                    $blocked = $f['limit'] === 0;
                    $pct = ($unlimited || $blocked) ? ($blocked ? 100 : 0) : min(100, round($f['used'] / max(1, $f['limit']) * 100));
                    $barColor = $blocked ? 'var(--color-surface-strong)' : ($pct >= 90 ? 'var(--color-error)' : ($pct >= 70 ? 'var(--color-warning)' : 'var(--color-primary)'));
                @endphp
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="text-muted" style="font-size:var(--fs-xs);">{{ $f['label'] }}</span>
                        <span class="text-ink font-semibold" style="font-size:var(--fs-xs);">
                            @if ($unlimited) 무제한
                            @elseif ($blocked) 미제공
                            @else {{ number_format($f['used']) }}/{{ number_format($f['limit']) }}
                            @endif
                        </span>
                    </div>
                    <div style="height:7px;border-radius:99px;background:var(--color-surface-strong);overflow:hidden;">
                        <div style="height:100%;width:{{ $unlimited ? 8 : $pct }}%;background:{{ $barColor }};border-radius:99px;"></div>
                    </div>
                    @unless ($unlimited || $blocked)
                        <div class="text-muted-soft mt-1" style="font-size:var(--fs-xs);">잔여 {{ number_format($f['remaining']) }}회</div>
                    @endunless
                </div>
            @endforeach
        </div>
    </div>

    {{-- 공지 + 최근 조회 --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {{-- 공지사항 --}}
        <div class="card overflow-hidden">
            <div class="flex items-center justify-between px-5 border-b border-hairline" style="height:52px;">
                <h2 class="text-ink font-semibold" style="font-size:var(--fs-sm);">공지사항</h2>
                <a href="{{ route('console.notices') }}" class="text-accent" style="font-size:var(--fs-xs);font-weight:600;">전체 →</a>
            </div>
            @forelse ($notices as $notice)
                <a href="{{ route('console.notices.show', $notice) }}" class="flex items-center gap-2 px-5 hover:bg-surface-soft transition" style="min-height:46px;border-top:{{ $loop->first ? '0' : '1px solid var(--color-hairline-soft)' }};">
                    <span class="badge" style="font-size:var(--fs-xs);padding:1px 7px;flex-shrink:0;">{{ $notice->category }}</span>
                    <span class="text-body flex-1 truncate" style="font-size:var(--fs-xs);">{{ $notice->title }}</span>
                    <span class="text-muted-soft" style="font-size:var(--fs-xs);flex-shrink:0;">{{ optional($notice->published_at)->format('m/d') }}</span>
                </a>
            @empty
                <div class="text-center text-muted-soft" style="padding:36px;font-size:var(--fs-xs);">등록된 공지가 없습니다.</div>
            @endforelse
        </div>

        {{-- 최근 순위 조회 --}}
        <div class="card overflow-hidden">
            <div class="flex items-center justify-between px-5 border-b border-hairline" style="height:52px;">
                <h2 class="text-ink font-semibold" style="font-size:var(--fs-sm);">최근 순위 조회</h2>
                <a href="/#hero-form" class="text-accent" style="font-size:var(--fs-xs);font-weight:600;">새 조회 →</a>
            </div>
            @if ($recent->isEmpty())
                <div class="text-center text-muted-soft" style="padding:36px;font-size:var(--fs-xs);">아직 조회한 순위가 없어요.</div>
            @else
                @foreach ($recent as $r)
                    <div class="flex items-center gap-2 px-5" style="min-height:46px;border-top:{{ $loop->first ? '0' : '1px solid var(--color-hairline-soft)' }};">
                        <span class="text-ink font-medium flex-1 truncate" style="font-size:var(--fs-xs);">{{ $r->keyword }}</span>
                        <span class="text-muted truncate" style="font-size:var(--fs-xs);max-width:120px;">{{ $r->place_name ?: '—' }}</span>
                        <span style="font-size:var(--fs-xs);flex-shrink:0;">
                            @if ($r->rank > 0 && $r->rank < 300)<span class="font-display text-ink">{{ $r->rank }}위</span>
                            @elseif ($r->rank < 0)<span class="text-muted-soft">차단</span>
                            @else<span class="text-muted-soft">300+</span>@endif
                        </span>
                    </div>
                @endforeach
            @endif
        </div>
    </div>

    {{-- 팝업 --}}
    @include('console.partials.popups', ['popups' => $popups])
@endsection
