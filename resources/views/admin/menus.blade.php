@extends('admin.layout')
@section('page-title', '메뉴 관리')

@section('admin-content')
<div style="max-width:1040px;">
    {{-- area 탭 --}}
    <div class="flex gap-2 mb-5 items-center flex-wrap">
        @foreach (['console' => '콘솔 메뉴', 'admin' => '관리 메뉴'] as $a => $l)
            <a href="{{ route('admin.menus', ['area' => $a]) }}" class="btn btn-sm {{ $area === $a ? 'btn-primary' : 'btn-secondary' }}">{{ $l }}</a>
        @endforeach
        <span class="text-muted-soft" style="font-size:12px;">⠿ 드래그로 순서·이동. 대분류와 미분류 항목은 최상위에서 자유 배치, 항목은 그룹 간 이동 가능</span>
    </div>

    @if ($errors->any())
        <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,#fff);color:var(--color-error);font-size:13px;">{{ $errors->first() }}</div>
    @endif

    {{-- 메뉴 추가 --}}
    <details class="card mb-6" style="padding:16px 20px;">
        <summary class="cursor-pointer text-ink font-semibold" style="font-size:14px;">＋ 메뉴 추가</summary>
        <div class="flex gap-8 flex-wrap mt-3">
            <form method="POST" action="{{ route('admin.menus.store') }}" class="flex gap-2 items-end flex-wrap">
                @csrf
                <input type="hidden" name="area" value="{{ $area }}"><input type="hidden" name="kind" value="group">
                <div><label class="block text-muted mb-1" style="font-size:12px;">＋ 대분류</label><input name="name" class="input" placeholder="그룹명" required></div>
                <div style="width:120px;"><label class="block text-muted mb-1" style="font-size:12px;">아이콘</label><input name="icon" class="input" placeholder="📊"></div>
                <button type="submit" class="btn btn-primary">대분류</button>
            </form>
            <form method="POST" action="{{ route('admin.menus.store') }}" class="flex gap-2 items-end flex-wrap">
                @csrf
                <input type="hidden" name="area" value="{{ $area }}"><input type="hidden" name="kind" value="item">
                <div><label class="block text-muted mb-1" style="font-size:12px;">＋ 미분류 항목</label><input name="name" class="input" placeholder="메뉴명" required></div>
                <div><label class="block text-muted mb-1" style="font-size:12px;">라우트명</label><input name="route" class="input" placeholder="console.rank"></div>
                <button type="submit" class="btn btn-secondary">항목</button>
            </form>
        </div>
    </details>

    {{-- 최상위 통합 트리 (대분류 + 미분류 항목, sort 순서대로) --}}
    <div data-sortable-top>
        @foreach ($roots as $node)
            @if ($node->is_group)
                @include('admin.partials.menu-group', ['g' => $node])
            @else
                <div class="card mb-3" data-id="{{ $node->id }}">
                    <div class="flex items-stretch">
                        <span class="tdrag text-muted-soft" style="cursor:move;display:flex;align-items:center;padding:0 10px;" title="드래그하여 위치 변경">⠿</span>
                        <div class="flex-1" style="border-left:1px solid var(--color-hairline-soft);">
                            @include('admin.partials.menu-item', ['item' => $node])
                        </div>
                    </div>
                </div>
            @endif
        @endforeach
    </div>
    @if (! $roots->count())
        <div class="card text-center" style="padding:40px;color:var(--color-muted);font-size:13px;">메뉴가 없습니다. 위에서 추가하세요.</div>
    @endif
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<script>
    const RF_CSRF = '{{ csrf_token() }}';
    function tgl(id) { const e = document.getElementById(id); if (e) e.classList.toggle('hidden'); }
    function rfPostOrder(items) {
        fetch('{{ route('admin.menus.reorder') }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': RF_CSRF }, body: JSON.stringify({ order: items }) });
    }
    function rfOrderOf(c) {
        const parent = c.dataset.parent || '';
        return [...c.children].filter(x => x.dataset.id).map((x, i) => ({ id: x.dataset.id, parent_id: parent, sort_order: i }));
    }
    (function () {
        if (typeof window.Sortable !== 'function') { console.error('SortableJS not loaded'); return; }
        // 최상위 통합 (대분류 .gdrag + 미분류 항목 .tdrag)
        const topEl = document.querySelector('[data-sortable-top]');
        if (topEl) {
            new Sortable(topEl, { handle: '.gdrag, .tdrag', draggable: '[data-id]', animation: 150, onEnd: function () {
                rfPostOrder([...topEl.children].filter(x => x.dataset.id).map((x, i) => ({ id: x.dataset.id, parent_id: '', sort_order: i })));
            } });
        }
        // 대분류 안 항목 (그룹 간 이동)
        document.querySelectorAll('[data-sortable-items]').forEach(function (el) {
            new Sortable(el, { group: 'menu-items', handle: '.drag', animation: 150, onEnd: function (e) { rfPostOrder(rfOrderOf(e.to)); if (e.from !== e.to) rfPostOrder(rfOrderOf(e.from)); } });
        });
    })();
    document.querySelectorAll('.menu-toggle').forEach(function (cb) {
        cb.addEventListener('change', function () {
            const fd = new URLSearchParams(); fd.append('is_active', cb.checked ? 1 : 0);
            fetch('/admin/menus/' + cb.dataset.id + '/toggle', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': RF_CSRF }, body: fd });
        });
    });
</script>
@endsection
