@extends('admin.layout')
@section('page-title', '메뉴 관리')

@section('admin-content')
<div style="max-width:1040px;">
    {{-- area 탭 --}}
    <div class="flex gap-2 mb-5 items-center flex-wrap">
        @foreach (['console' => '콘솔 메뉴', 'admin' => '관리 메뉴'] as $a => $l)
            <a href="{{ route('admin.menus', ['area' => $a]) }}" class="btn btn-sm {{ $area === $a ? 'btn-primary' : 'btn-secondary' }}">{{ $l }}</a>
        @endforeach
        <span class="text-muted-soft" style="font-size:12px;">⠿ 드래그로 순서·이동 (항목은 그룹 간 이동 가능)</span>
    </div>

    @if ($errors->any())
        <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,#fff);color:var(--color-error);font-size:13px;">{{ $errors->first() }}</div>
    @endif

    {{-- 대분류 추가 --}}
    <details class="card mb-6" style="padding:16px 20px;">
        <summary class="cursor-pointer text-ink font-semibold" style="font-size:14px;">＋ 대분류 추가</summary>
        <form method="POST" action="{{ route('admin.menus.store') }}" class="flex gap-2 items-end flex-wrap mt-3">
            @csrf
            <input type="hidden" name="area" value="{{ $area }}"><input type="hidden" name="kind" value="group">
            <div style="flex:1;min-width:160px;"><label class="block text-muted mb-1" style="font-size:12px;">이름</label><input name="name" class="input" required></div>
            <div style="width:160px;"><label class="block text-muted mb-1" style="font-size:12px;">아이콘(이모지)</label><input name="icon" class="input" placeholder="📊"></div>
            <button type="submit" class="btn btn-primary">추가</button>
        </form>
    </details>

    {{-- 대분류 트리 --}}
    <div data-sortable-groups>
        @foreach ($roots->where('is_group', true) as $g)
            @include('admin.partials.menu-group', ['g' => $g])
        @endforeach
    </div>

    {{-- 미분류 최상위 항목 --}}
    @php $topItems = $roots->where('is_group', false); @endphp
    <div class="card mt-2">
        <div class="flex items-center justify-between px-3 py-2.5" style="background:var(--color-surface-card);border-bottom:1px solid var(--color-hairline);">
            <span class="font-semibold text-muted" style="font-size:13px;">미분류 항목 <span class="text-muted-soft">(대분류로 드래그해 정리)</span></span>
            <button type="button" class="btn btn-ghost btn-sm" onclick="tgl('add-top')">＋ 항목</button>
        </div>
        <div id="add-top" class="hidden px-3 py-3" style="background:var(--color-surface-soft);">
            <form method="POST" action="{{ route('admin.menus.store') }}" class="flex gap-2 items-end flex-wrap">
                @csrf
                <input type="hidden" name="area" value="{{ $area }}"><input type="hidden" name="kind" value="item">
                <div><label class="block text-muted mb-1" style="font-size:11px;">메뉴명</label><input name="name" class="input" required></div>
                <div><label class="block text-muted mb-1" style="font-size:11px;">라우트명</label><input name="route" class="input" placeholder="console.rank"></div>
                <button type="submit" class="btn btn-primary btn-sm">추가</button>
            </form>
        </div>
        <div data-sortable-items data-parent="">
            @foreach ($topItems as $item)
                @include('admin.partials.menu-item', ['item' => $item])
            @endforeach
        </div>
        @if (! $topItems->count() && ! $roots->where('is_group', true)->count())
            <div class="text-center text-muted-soft" style="padding:24px;font-size:13px;">메뉴가 없습니다. 대분류나 항목을 추가하세요.</div>
        @endif
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<script>
    const CSRF = '{{ csrf_token() }}';
    function tgl(id) { const e = document.getElementById(id); if (e) e.classList.toggle('hidden'); }
    function postOrder(items) {
        fetch('{{ route('admin.menus.reorder') }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF }, body: JSON.stringify({ order: items }) });
    }
    function orderOf(c) {
        const parent = c.dataset.parent || '';
        return [...c.children].filter(x => x.dataset.id).map((x, i) => ({ id: x.dataset.id, parent_id: parent, sort_order: i }));
    }
    document.querySelectorAll('[data-sortable-items]').forEach(el => {
        new Sortable(el, { group: 'menu-items', handle: '.drag', animation: 150, onEnd: e => { postOrder(orderOf(e.to)); if (e.from !== e.to) postOrder(orderOf(e.from)); } });
    });
    const gEl = document.querySelector('[data-sortable-groups]');
    if (gEl) new Sortable(gEl, { handle: '.gdrag', animation: 150, onEnd: () => {
        postOrder([...gEl.children].filter(x => x.dataset.id).map((x, i) => ({ id: x.dataset.id, parent_id: '', sort_order: i })));
    }});
    document.querySelectorAll('.menu-toggle').forEach(cb => cb.addEventListener('change', () => {
        const fd = new URLSearchParams(); fd.append('is_active', cb.checked ? 1 : 0);
        fetch('/admin/menus/' + cb.dataset.id + '/toggle', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': CSRF }, body: fd });
    }));
</script>
@endsection
