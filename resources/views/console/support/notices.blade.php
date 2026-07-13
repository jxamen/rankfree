@extends('console.layout')
@section('page-title', '공지사항')

@section('console-content')
<div class="card overflow-hidden">
    @forelse ($notices as $notice)
        <a href="{{ route('console.notices.show', $notice) }}" class="flex items-center gap-3 px-5 hover:bg-surface-soft transition" style="border-top:{{ $loop->first ? '0' : '1px solid var(--color-hairline-soft)' }};min-height:56px;">
            {{-- No — 최신이 큰 번호(내림차순), 페이지네이션 반영 --}}
            <span class="text-muted-soft text-center" style="font-size:var(--fs-xs);width:36px;flex-shrink:0;">{{ $notices->total() - ($notices->firstItem() - 1) - $loop->index }}</span>
            @if ($notice->is_pinned)<span title="고정" style="color:var(--color-badge-orange);">📌</span>@endif
            <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;flex-shrink:0;">{{ $notice->category }}</span>
            <span class="text-ink font-medium flex-1 truncate" style="font-size:var(--fs-sm);">{{ $notice->title }}</span>
            <span class="text-muted-soft" style="font-size:var(--fs-xs);flex-shrink:0;">{{ optional($notice->published_at)->format('Y-m-d') }}</span>
        </a>
    @empty
        <div class="text-center text-muted-soft" style="padding:64px 24px;font-size:var(--fs-sm);">등록된 공지사항이 없습니다.</div>
    @endforelse
</div>
<div class="mt-4">{{ $notices->links() }}</div>
@endsection
