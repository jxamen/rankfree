@extends('console.layout')
@section('page-title', '대시보드')

@section('page-actions')
    <a href="/#hero-form" class="btn btn-primary btn-sm">+ 순위 조회</a>
@endsection

@section('console-content')
    {{-- 통계 카드 --}}
    <div class="grid gap-4 md:grid-cols-4 mb-6">
        @php
            $stats = [
                ['추적 순위', $usedSlots.' / '.$maxSlots, '무료 슬롯'],
                ['이번 달 조회', number_format($monthCount).'회', '순위 조회 누적'],
                ['평균 순위', '—', '추적 시작 후 표시'],
                ['순위 변동', '—', '전일 대비'],
            ];
        @endphp
        @foreach ($stats as $s)
        <div class="card p-5">
            <div class="text-muted" style="font-size:13px;font-weight:600;">{{ $s[0] }}</div>
            <div class="font-display text-ink mt-2" style="font-size:28px;">{{ $s[1] }}</div>
            <div class="text-muted-soft mt-1" style="font-size:12px;">{{ $s[2] }}</div>
        </div>
        @endforeach
    </div>

    {{-- 최근 순위 조회 --}}
    <div class="card overflow-hidden">
        <div class="flex items-center justify-between px-6 border-b border-hairline" style="height:56px;">
            <h2 class="text-ink font-semibold" style="font-size:15px;">최근 순위 조회</h2>
            <a href="/#hero-form" class="text-accent" style="font-size:13px;font-weight:600;">새 조회 →</a>
        </div>

        @if ($recent->isEmpty())
            <div class="text-center" style="padding:64px 24px;">
                <div style="font-size:32px;opacity:.4;">📊</div>
                <p class="text-muted mt-3" style="font-size:15px;">아직 조회한 순위가 없어요</p>
                <p class="text-muted-soft mt-1" style="font-size:13px;">키워드로 내 플레이스 순위를 조회해 보세요.</p>
                <a href="/#hero-form" class="btn btn-primary btn-sm mt-5">순위 조회하기</a>
            </div>
        @else
            <table class="w-full">
                <thead>
                    <tr class="text-muted" style="font-size:12px;">
                        <th class="text-left font-semibold px-6 py-3">키워드</th>
                        <th class="text-left font-semibold px-6 py-3">플레이스</th>
                        <th class="text-right font-semibold px-6 py-3">순위</th>
                        <th class="text-right font-semibold px-6 py-3">리뷰</th>
                        <th class="text-right font-semibold px-6 py-3">조회일</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recent as $r)
                    <tr style="border-top:1px solid var(--color-hairline-soft);font-size:14px;">
                        <td class="px-6 py-3 text-ink font-medium">{{ $r->keyword }}</td>
                        <td class="px-6 py-3 text-body">{{ $r->place_name ?: '—' }}</td>
                        <td class="px-6 py-3 text-right">
                            @if ($r->rank > 0 && $r->rank < 300)
                                <span class="font-display text-ink" style="font-size:15px;">{{ $r->rank }}위</span>
                            @elseif ($r->rank < 0)
                                <span class="text-muted-soft">차단</span>
                            @else
                                <span class="text-muted-soft">300위 밖</span>
                            @endif
                        </td>
                        <td class="px-6 py-3 text-right text-muted">{{ $r->review_count !== null ? number_format($r->review_count) : '—' }}</td>
                        <td class="px-6 py-3 text-right text-muted-soft" style="font-size:13px;">{{ $r->created_at->format('m/d H:i') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
