{{-- 메뉴 항목(페이지) 행 --}}
<div class="menu-item" data-id="{{ $item->id }}">
    <div class="flex items-center gap-2 px-3 py-2 border-t border-hairline-soft">
        <span class="drag text-muted-soft" style="cursor:move;" title="드래그하여 이동">⠿</span>
        <span class="text-ink" style="font-size:14px;">{{ $item->name }}</span>
        <a href="{{ $item->resolvedUrl() ?? '#' }}" target="{{ $item->target === '_blank' ? '_blank' : '_self' }}" class="text-muted-soft flex-1 truncate" style="font-size:12px;">{{ $item->route ?: ($item->url ?: '—') }}</a>
        <label title="노출" style="display:inline-flex;align-items:center;">
            <input type="checkbox" class="menu-toggle" data-id="{{ $item->id }}" @checked($item->is_active)>
        </label>
        <button type="button" class="btn btn-ghost btn-sm" onclick="tgl('edit-{{ $item->id }}')">수정</button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="tgl('perm-{{ $item->id }}')">권한</button>
    </div>

    {{-- 수정 폼 --}}
    <div id="edit-{{ $item->id }}" class="hidden px-3 py-3 border-t border-hairline-soft" style="background:var(--color-surface-soft);">
        <form method="POST" action="{{ route('admin.menus.update', $item) }}" class="flex gap-2 items-end flex-wrap">
            @csrf @method('PUT')
            <div style="flex:1;min-width:120px;"><label class="block text-muted mb-1" style="font-size:11px;">메뉴명</label><input name="name" class="input" value="{{ $item->name }}" required></div>
            <div style="flex:1;min-width:120px;"><label class="block text-muted mb-1" style="font-size:11px;">라우트명</label><input name="route" class="input" value="{{ $item->route }}" placeholder="console.rank"></div>
            <div style="flex:1;min-width:120px;"><label class="block text-muted mb-1" style="font-size:11px;">URL(라우트 없을 때)</label><input name="url" class="input" value="{{ $item->url }}" placeholder="/path"></div>
            <label class="flex items-center gap-1.5 text-muted" style="font-size:12px;height:40px;"><input type="checkbox" name="target" value="_blank" @checked($item->target === '_blank')> 새창</label>
            <label class="flex items-center gap-1.5 text-muted" style="font-size:12px;height:40px;"><input type="checkbox" name="is_active" value="1" @checked($item->is_active)> 노출</label>
            <button type="submit" class="btn btn-secondary btn-sm">저장</button>
            <button type="submit" form="del-{{ $item->id }}" class="btn btn-ghost btn-sm" style="color:var(--color-error);" onclick="return confirm('삭제하시겠습니까?')">삭제</button>
        </form>
        <form id="del-{{ $item->id }}" method="POST" action="{{ route('admin.menus.destroy', $item) }}">@csrf @method('DELETE')</form>
    </div>

    {{-- 권한 매트릭스 --}}
    <div id="perm-{{ $item->id }}" class="hidden border-t border-hairline-soft">
        @include('admin.partials.menu-perm', ['menu' => $item])
    </div>
</div>
