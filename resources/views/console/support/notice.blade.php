@extends('console.layout')
@section('page-title', '공지사항')

@section('page-actions')
    <a href="{{ route('console.notices') }}" class="btn btn-secondary btn-sm">← 목록</a>
@endsection

@section('console-content')
<div>
    <div class="card p-6">
        <div class="flex items-center gap-2 mb-3">
            @if ($notice->is_pinned)<span style="color:var(--color-badge-orange);">📌</span>@endif
            <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">{{ $notice->category }}</span>
            <span class="text-muted-soft" style="font-size:var(--fs-xs);">{{ optional($notice->published_at)->format('Y-m-d H:i') }} · 조회 {{ number_format($notice->views) }}</span>
        </div>
        <h1 class="text-ink font-display" style="font-size:var(--fs-2xl);">{{ $notice->title }}</h1>
        <div class="mt-5 text-body" style="font-size:var(--fs-sm);line-height:1.85;border-top:1px solid var(--color-hairline-soft);padding-top:20px;">{!! $notice->body !!}</div>
    </div>

    <div class="flex items-center justify-between mt-4">
        <div>
            @if ($prev)<a href="{{ route('console.notices.show', $prev) }}" class="btn btn-secondary btn-sm">← 이전글</a>@endif
        </div>
        <div class="flex items-center gap-2">
            @if ($next)<a href="{{ route('console.notices.show', $next) }}" class="btn btn-secondary btn-sm">다음글 →</a>@endif
            <a href="{{ route('console.notices') }}" class="btn btn-secondary btn-sm">목록</a>
        </div>
    </div>
</div>
@endsection
