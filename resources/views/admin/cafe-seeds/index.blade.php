@extends('admin.layout')
@section('page-title', '카페 수집 글감')

@section('page-actions')
    <a href="{{ route('admin.community-seeds') }}" class="btn btn-secondary btn-sm">글밥 관리</a>
    <form method="POST" action="{{ route('admin.cafe-seeds.crawl') }}" style="display:inline;">
        @csrf
        <button type="submit" class="btn btn-primary btn-sm" @disabled($running)>{{ $running ? '수집 진행 중…' : '지금 수집' }}</button>
    </form>
@endsection

@section('crumb-parent', 'admin.community-seeds')

@section('admin-content')
<x-console.page-head title="카페 수집 글감" desc="네이버 카페 인기글·댓글 수집 원본입니다. 글밥으로 전환된 소재는 페르소나가 <b>AI 재작성</b>해 커뮤니티에 사용합니다 (매일 05:10 자동 수집)" />

@if ($running)
    <div class="card p-4 mb-4" style="border-left:none;">
        <span class="text-body" style="font-size:var(--fs-xs);"><i class="fa-solid fa-rotate fa-spin"></i> 수집이 진행 중입니다 ({{ $running }} 시작) — 완료까지 몇 분 걸립니다. 잠시 후 새로고침하세요.</span>
    </div>
@endif

{{-- 요약 --}}
<div class="text-muted mb-4" style="font-size:var(--fs-xs);">
    수집 글 <b class="text-ink font-mono">{{ number_format($stats['articles']) }}</b>건 ·
    댓글 <b class="text-ink font-mono">{{ number_format($stats['comments']) }}</b>건 ·
    글밥 전환 <b class="text-ink font-mono">{{ number_format($stats['seeded']) }}</b>건 ·
    마지막 수집 {{ $stats['lastCrawledAt'] ? \Illuminate\Support\Carbon::parse($stats['lastCrawledAt'])->timezone('Asia/Seoul')->format('Y-m-d H:i') : '—' }}
</div>

{{-- 필터 --}}
<form method="GET" class="flex items-center gap-2 mb-4">
    <input name="q" value="{{ $q }}" class="input" style="width:260px;" placeholder="제목 검색">
    <select name="state" class="input" style="width:160px;" onchange="this.form.submit()">
        <option value="" @selected($state === '')>전체</option>
        <option value="seeded" @selected($state === 'seeded')>글밥 전환됨</option>
        <option value="unseeded" @selected($state === 'unseeded')>미전환</option>
        <option value="used" @selected($state === 'used')>사용됨(재작성)</option>
    </select>
    <button type="submit" class="btn btn-secondary btn-sm">검색</button>
</form>

{{-- 목록 --}}
<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:900px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);border-bottom:1px solid var(--color-hairline-soft);">
                    <th class="text-right px-3 py-3 font-semibold" style="width:56px;">No</th>
                    <th class="text-left px-4 py-3 font-semibold">제목</th>
                    <th class="text-left px-3 py-3 font-semibold" style="width:110px;">작성자</th>
                    <th class="text-left px-3 py-3 font-semibold" style="width:130px;">작성일</th>
                    <th class="text-right px-3 py-3 font-semibold" style="width:70px;">조회</th>
                    <th class="text-right px-3 py-3 font-semibold" style="width:90px;">댓글(수집)</th>
                    <th class="text-center px-3 py-3 font-semibold" style="width:90px;">글밥</th>
                    <th class="text-center px-3 py-3 font-semibold" style="width:90px;">사용여부</th>
                    <th class="text-left px-4 py-3 font-semibold" style="width:150px;">사용(재작성)</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($articles as $a)
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        <td class="px-3 py-3 text-right text-muted-soft font-mono" style="font-size:var(--fs-xs);">{{ $articles->total() - $articles->firstItem() + 1 - $loop->index }}</td>
                        <td class="px-4 py-3" style="max-width:420px;">
                            <a href="{{ route('admin.cafe-seeds.show', $a) }}" class="text-ink font-semibold truncate" style="font-size:var(--fs-xs);display:block;max-width:420px;">{{ $a->title }}</a>
                            @unless ($a->body)<span class="text-muted-soft" style="font-size:var(--fs-xs);">본문 없음</span>@endunless
                        </td>
                        <td class="px-3 py-3 text-muted" style="font-size:var(--fs-xs);">{{ $a->writer ?: '—' }}</td>
                        <td class="px-3 py-3 text-muted" style="font-size:var(--fs-xs);">{{ $a->wrote_at?->timezone('Asia/Seoul')->format('Y-m-d H:i') ?? '—' }}</td>
                        <td class="px-3 py-3 text-right text-body font-mono" style="font-size:var(--fs-xs);">{{ number_format($a->read_count) }}</td>
                        <td class="px-3 py-3 text-right text-body font-mono" style="font-size:var(--fs-xs);">{{ $a->comment_count }}({{ $a->comments_count }})</td>
                        <td class="px-3 py-3 text-center" style="font-size:var(--fs-xs);">
                            @if ($a->seed_id)
                                <span class="badge" style="font-size:var(--fs-xs);padding:2px 9px;background:color-mix(in srgb,var(--color-success) 14%,var(--color-canvas));color:var(--color-success);">전환</span>
                            @else
                                <span class="text-muted-soft">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-center">
                            @if ($a->seed)
                                <form method="POST" action="{{ route('admin.cafe-seeds.toggle-seed', $a) }}">
                                    @csrf
                                    <button type="submit" class="badge" title="재작성 소재로 사용할지 전환" style="font-size:var(--fs-xs);padding:2px 9px;cursor:pointer;{{ $a->seed->is_active ? 'background:color-mix(in srgb,var(--color-success) 14%,var(--color-canvas));color:var(--color-success);' : '' }}">{{ $a->seed->is_active ? '사용' : '미사용' }}</button>
                                </form>
                            @else
                                <span class="text-muted-soft" style="font-size:var(--fs-xs);">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-muted" style="font-size:var(--fs-xs);">
                            @if ($a->seed && $a->seed->used_count > 0)
                                <span class="text-ink font-mono">{{ $a->seed->used_count }}회</span>
                                <span class="text-muted-soft">· {{ $a->seed->last_used_at?->timezone('Asia/Seoul')->format('m-d H:i') ?? '' }}</span>
                            @else
                                <span class="text-muted-soft">미사용</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted-soft" style="padding:40px;font-size:var(--fs-xs);">수집된 글이 없습니다. 우측 상단 <b>지금 수집</b>을 누르거나 스케줄(매일 05:10)을 기다리세요.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-4">{{ $articles->links() }}</div>
@endsection
