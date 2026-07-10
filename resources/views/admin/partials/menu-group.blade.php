{{-- 메뉴 그룹(대분류/중분류) — 재귀 --}}
@php
    $kids = $children->get($g->id, collect());
    $subItems = $kids->where('is_group', false);
    $subGroups = $kids->where('is_group', true);
@endphp
<div class="card mb-3 menu-group" data-id="{{ $g->id }}">
    {{-- 헤더 --}}
    <div class="flex items-center gap-2 px-3 py-2.5" style="background:var(--color-surface-card);border-bottom:1px solid var(--color-hairline);">
        <span class="gdrag text-muted-soft" style="cursor:move;" title="드래그하여 순서 변경">⠿</span>
        @if ($g->icon)<span style="font-size:15px;">{{ $g->icon }}</span>@endif
        <span class="font-semibold text-ink flex-1" style="font-size:14px;">{{ $g->name }}</span>
        <label title="노출" style="display:inline-flex;align-items:center;"><input type="checkbox" class="menu-toggle" data-id="{{ $g->id }}" @checked($g->is_active)></label>
        <button type="button" class="btn btn-ghost btn-sm" onclick="tgl('add-{{ $g->id }}')">+ 하위</button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="tgl('edit-{{ $g->id }}')">수정</button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="tgl('perm-{{ $g->id }}')">권한</button>
        <button type="submit" form="delg-{{ $g->id }}" class="btn btn-ghost btn-sm" style="color:var(--color-error);" onclick="return confirm('삭제하시겠습니까?')">삭제</button>
        <form id="delg-{{ $g->id }}" method="POST" action="{{ route('admin.menus.destroy', $g) }}">@csrf @method('DELETE')</form>
    </div>

    {{-- 수정 --}}
    <div id="edit-{{ $g->id }}" class="hidden px-3 py-3" style="background:var(--color-surface-soft);border-bottom:1px solid var(--color-hairline-soft);">
        <form method="POST" action="{{ route('admin.menus.update', $g) }}" class="flex gap-2 items-end flex-wrap">
            @csrf @method('PUT')
            <div style="flex:1;min-width:140px;"><label class="block text-muted mb-1" style="font-size:11px;">이름</label><input name="name" class="input" value="{{ $g->name }}" required></div>
            <div style="width:140px;"><label class="block text-muted mb-1" style="font-size:11px;">아이콘(이모지)</label><input name="icon" class="input" value="{{ $g->icon }}"></div>
            <label class="flex items-center gap-1.5 text-muted" style="font-size:12px;height:40px;"><input type="checkbox" name="is_active" value="1" @checked($g->is_active)> 노출</label>
            <button type="submit" class="btn btn-secondary btn-sm">저장</button>
        </form>
    </div>

    {{-- 권한 --}}
    <div id="perm-{{ $g->id }}" class="hidden" style="border-bottom:1px solid var(--color-hairline-soft);">
        @include('admin.partials.menu-perm', ['menu' => $g])
    </div>

    {{-- 하위 추가 --}}
    <div id="add-{{ $g->id }}" class="hidden px-3 py-3" style="background:var(--color-surface-soft);border-bottom:1px solid var(--color-hairline-soft);">
        <div class="flex gap-6 flex-wrap">
            <form method="POST" action="{{ route('admin.menus.store') }}" class="flex gap-2 items-end flex-wrap">
                @csrf
                <input type="hidden" name="area" value="{{ $area }}"><input type="hidden" name="kind" value="item"><input type="hidden" name="parent_id" value="{{ $g->id }}">
                <div><label class="block text-muted mb-1" style="font-size:11px;">＋ 페이지 항목</label><input name="name" class="input" placeholder="메뉴명" required></div>
                <div><label class="block text-muted mb-1" style="font-size:11px;">라우트명</label><input name="route" class="input" placeholder="console.rank"></div>
                <button type="submit" class="btn btn-primary btn-sm">항목</button>
            </form>
            <form method="POST" action="{{ route('admin.menus.store') }}" class="flex gap-2 items-end flex-wrap">
                @csrf
                <input type="hidden" name="area" value="{{ $area }}"><input type="hidden" name="kind" value="group"><input type="hidden" name="parent_id" value="{{ $g->id }}">
                <div><label class="block text-muted mb-1" style="font-size:11px;">＋ 중분류</label><input name="name" class="input" placeholder="중분류명" required></div>
                <button type="submit" class="btn btn-secondary btn-sm">중분류</button>
            </form>
        </div>
    </div>

    {{-- 직속 항목 (드래그 정렬 대상) --}}
    <div data-sortable-items data-parent="{{ $g->id }}">
        @foreach ($subItems as $item)
            @include('admin.partials.menu-item', ['item' => $item])
        @endforeach
    </div>

    {{-- 중분류 (재귀) --}}
    @if ($subGroups->count())
        <div style="padding:10px 10px 2px 20px;">
            @foreach ($subGroups as $sub)
                @include('admin.partials.menu-group', ['g' => $sub])
            @endforeach
        </div>
    @endif
</div>
