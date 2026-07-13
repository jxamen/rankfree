@extends('admin.layout')
@section('page-title', '배너 관리')

@section('page-actions')
    <a href="{{ route('admin.banners.create') }}" class="btn btn-primary btn-sm">+ 새 배너</a>
@endsection

@section('admin-content')
<p class="text-muted-soft mb-4" style="font-size:var(--fs-xs);">대시보드 상단에 노출되는 홍보 배너입니다. 노출 순서·기간을 지정할 수 있습니다.</p>
<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:1200px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);border-bottom:1px solid var(--color-hairline-soft);">
                    <th class="text-center px-4 py-3 font-semibold" style="width:64px;">노출</th>
                    <th class="text-left px-3 py-3 font-semibold" style="width:400px;">미리보기</th>
                    <th class="text-left px-3 py-3 font-semibold" style="width:190px;">유형</th>
                    <th class="text-left px-3 py-3 font-semibold">제목</th>
                    <th class="text-left px-3 py-3 font-semibold" style="width:170px;">기간</th>
                    <th class="text-right px-3 py-3 font-semibold" style="width:56px;">순서</th>
                    <th class="text-right px-4 py-3 font-semibold" style="width:120px;">관리</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($banners as $banner)
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        <td class="text-center px-4 py-3">
                            <form method="POST" action="{{ route('admin.banners.toggle', $banner) }}">
                                @csrf
                                <label class="rf-switch"><input type="checkbox" onchange="this.form.submit()" {{ $banner->is_active ? 'checked' : '' }}><span class="rf-track"></span></label>
                            </form>
                        </td>
                        <td class="px-3 py-3">
                            @if ($banner->image_url)
                                <div style="position:relative;border-radius:8px;overflow:hidden;height:48px;background:center/cover no-repeat url('{{ $banner->image_url }}');">
                                    <span style="position:absolute;inset:0;display:flex;align-items:center;padding:0 12px;background:linear-gradient(90deg,rgba(0,0,0,.55),rgba(0,0,0,.1));color:#fff;font-size:var(--fs-xs);font-weight:600;">{{ \Illuminate\Support\Str::limit($banner->title, 30) }}</span>
                                </div>
                            @else
                                <div style="border-radius:8px;overflow:hidden;height:48px;display:flex;align-items:center;padding:0 12px;background:{{ $banner->bg_color ?: 'var(--color-ink)' }};color:{{ $banner->text_color ?: '#fff' }};font-size:var(--fs-xs);font-weight:600;">{{ \Illuminate\Support\Str::limit($banner->title, 30) }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-3"><span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">{{ $banner->typeLabel() }}</span></td>
                        <td class="px-3 py-3"><a href="{{ route('admin.banners.edit', $banner) }}" class="text-ink font-medium hover:underline" style="font-size:var(--fs-xs);">{{ $banner->title }}</a></td>
                        <td class="px-3 py-3 text-muted-soft" style="font-size:var(--fs-xs);">
                            {{ optional($banner->starts_at)->format('m/d') ?? '상시' }} ~ {{ optional($banner->ends_at)->format('m/d') ?? '상시' }}
                        </td>
                        <td class="px-3 py-3 text-right text-muted-soft" style="font-size:var(--fs-xs);">{{ $banner->sort_order }}</td>
                        <td class="px-4 py-3 text-right" style="white-space:nowrap;">
                            <a href="{{ route('admin.banners.edit', $banner) }}" class="btn btn-secondary btn-sm">수정</a>
                            <form method="POST" action="{{ route('admin.banners.destroy', $banner) }}" style="display:inline;" onsubmit="return confirm('이 배너를 삭제할까요?');">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-error);">삭제</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted-soft" style="padding:48px;font-size:var(--fs-xs);">등록된 배너가 없습니다.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-4">{{ $banners->links() }}</div>
@endsection
