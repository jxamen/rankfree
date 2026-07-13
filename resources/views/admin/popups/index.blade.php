@extends('admin.layout')
@section('page-title', '팝업 관리')

@section('page-actions')
    <a href="{{ route('admin.popups.create') }}" class="btn btn-primary btn-sm">+ 새 팝업</a>
@endsection

@section('admin-content')
<p class="text-muted-soft mb-4" style="font-size:var(--fs-xs);">대시보드 진입 시 표시되는 팝업입니다. 위치·크기·노출 기간을 지정할 수 있습니다.</p>
<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:820px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);border-bottom:1px solid var(--color-hairline-soft);">
                    <th class="text-center px-4 py-3 font-semibold" style="width:64px;">노출</th>
                    <th class="text-left px-3 py-3 font-semibold">제목</th>
                    <th class="text-left px-3 py-3 font-semibold" style="width:90px;">위치</th>
                    <th class="text-right px-3 py-3 font-semibold" style="width:70px;">너비</th>
                    <th class="text-left px-3 py-3 font-semibold" style="width:170px;">기간</th>
                    <th class="text-right px-4 py-3 font-semibold" style="width:120px;">관리</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($popups as $popup)
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        <td class="text-center px-4 py-3">
                            <form method="POST" action="{{ route('admin.popups.toggle', $popup) }}">
                                @csrf
                                <label class="rf-switch"><input type="checkbox" onchange="this.form.submit()" {{ $popup->is_active ? 'checked' : '' }}><span class="rf-track"></span></label>
                            </form>
                        </td>
                        <td class="px-3 py-3"><a href="{{ route('admin.popups.edit', $popup) }}" class="text-ink font-medium hover:underline" style="font-size:var(--fs-xs);">{{ $popup->title }}</a></td>
                        <td class="px-3 py-3"><span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">{{ $popup->positionLabel() }}</span></td>
                        <td class="px-3 py-3 text-right text-muted-soft" style="font-size:var(--fs-xs);">{{ $popup->width }}px</td>
                        <td class="px-3 py-3 text-muted-soft" style="font-size:var(--fs-xs);">{{ optional($popup->starts_at)->format('m/d') ?? '상시' }} ~ {{ optional($popup->ends_at)->format('m/d') ?? '상시' }}</td>
                        <td class="px-4 py-3 text-right" style="white-space:nowrap;">
                            <a href="{{ route('admin.popups.edit', $popup) }}" class="btn btn-secondary btn-sm">수정</a>
                            <form method="POST" action="{{ route('admin.popups.destroy', $popup) }}" style="display:inline;" onsubmit="return confirm('이 팝업을 삭제할까요?');">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-error);">삭제</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted-soft" style="padding:48px;font-size:var(--fs-xs);">등록된 팝업이 없습니다.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-4">{{ $popups->links() }}</div>
@endsection
