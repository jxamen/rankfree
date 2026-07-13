@extends('admin.layout')
@section('page-title', '공지사항 관리')

@section('page-actions')
    <a href="{{ route('admin.notices.create') }}" class="btn btn-primary btn-sm">+ 새 공지</a>
@endsection

@section('admin-content')
<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:820px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);border-bottom:1px solid var(--color-hairline-soft);">
                    <th class="text-center px-4 py-3 font-semibold" style="width:70px;">게시</th>
                    <th class="text-left px-3 py-3 font-semibold" style="width:100px;">분류</th>
                    <th class="text-left px-3 py-3 font-semibold">제목</th>
                    <th class="text-right px-3 py-3 font-semibold" style="width:70px;">조회</th>
                    <th class="text-right px-3 py-3 font-semibold" style="width:130px;">게시일</th>
                    <th class="text-right px-4 py-3 font-semibold" style="width:120px;">관리</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($notices as $notice)
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        <td class="text-center px-4 py-3">
                            <form method="POST" action="{{ route('admin.notices.toggle', $notice) }}">
                                @csrf
                                <label class="rf-switch"><input type="checkbox" onchange="this.form.submit()" {{ $notice->is_published ? 'checked' : '' }}><span class="rf-track"></span></label>
                            </form>
                        </td>
                        <td class="px-3 py-3"><span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">{{ $notice->category }}</span></td>
                        <td class="px-3 py-3">
                            @if ($notice->is_pinned)<span title="상단 고정" style="color:var(--color-badge-orange);">📌</span> @endif
                            <a href="{{ route('admin.notices.edit', $notice) }}" class="text-ink font-medium hover:underline" style="font-size:var(--fs-xs);">{{ $notice->title }}</a>
                        </td>
                        <td class="px-3 py-3 text-right text-muted" style="font-size:var(--fs-xs);">{{ number_format($notice->views) }}</td>
                        <td class="px-3 py-3 text-right text-muted-soft" style="font-size:var(--fs-xs);">{{ optional($notice->published_at)->format('Y-m-d') ?? '—' }}</td>
                        <td class="px-4 py-3 text-right" style="white-space:nowrap;">
                            <a href="{{ route('admin.notices.edit', $notice) }}" class="btn btn-secondary btn-sm">수정</a>
                            <form method="POST" action="{{ route('admin.notices.destroy', $notice) }}" style="display:inline;" onsubmit="return confirm('이 공지를 삭제할까요?');">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-error);">삭제</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted-soft" style="padding:48px;font-size:var(--fs-xs);">등록된 공지사항이 없습니다.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-4">{{ $notices->links() }}</div>
@endsection
