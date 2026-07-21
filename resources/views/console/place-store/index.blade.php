@extends('console.layout')
@section('page-title', '플레이스 개별 분석')
@section('active-menu', 'console.place-store')

@section('console-content')
<div>
    <div class="flex items-center gap-2 mb-4">
        <a href="{{ route('console.compete') }}" class="btn btn-secondary btn-sm">경쟁 분석</a>
        <a href="{{ route('console.place-store') }}" class="btn btn-primary btn-sm">개별 분석</a>
    </div>

    <x-console.page-head title="플레이스 개별 분석" desc="매장 1곳을 키워드 기준으로 분석해 순위, N1 유사도, N2 관련성, N3 랭킹 점수와 리뷰·키워드 신호를 확인합니다." />

    @if ($errors->any())
        <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('console.place-store.store') }}" class="card p-5 mb-5" id="ps-form">
        @csrf
        <div class="grid grid-cols-1 lg:grid-cols-[minmax(260px,1fr)_260px_auto] gap-3 items-start">
            <div>
                <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">플레이스 URL 또는 ID</label>
                <input name="place" id="ps-place" class="input" value="{{ old('place') }}" placeholder="https://map.naver.com/... 또는 m.place URL, placeId" required autocomplete="off">
                <div id="ps-place-info" class="mt-1" style="font-size:var(--fs-xs);min-height:16px;"></div>
            </div>
            <div>
                <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">진단 키워드</label>
                <input name="keyword" class="input" value="{{ old('keyword') }}" placeholder="논현삼계탕" required autocomplete="off">
            </div>
            <div style="padding-top:23px;">
                <button type="submit" class="btn btn-primary" style="height:42px;">분석하기</button>
            </div>
        </div>
        <p class="text-muted-soft mt-3" style="font-size:var(--fs-xs);">상위 30개 경쟁 데이터와 대상 매장 상세·리뷰 신호를 함께 수집하므로 20~60초 정도 걸릴 수 있습니다.</p>
    </form>

    <form method="GET" class="card p-3 mb-4">
        <div class="flex items-center gap-2 flex-wrap">
            <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">저장된 개별 분석</div>
            <div style="margin-left:auto;display:flex;align-items:center;gap:6px;">
                @if ($q)<a href="{{ route('console.place-store') }}" class="btn btn-ghost btn-sm" style="height:36px;">초기화</a>@endif
                <input name="q" value="{{ $q }}" class="input" style="width:260px;font-size:var(--fs-xs);" placeholder="키워드 · 매장명 검색">
                <button type="submit" class="btn btn-secondary btn-sm" style="height:36px;">검색</button>
            </div>
        </div>
    </form>

    <div class="card overflow-x-auto">
        <table class="w-full" style="min-width:980px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);">
                    <th class="text-right px-4 py-3 font-semibold" style="width:56px;">No</th>
                    <th class="text-left px-3 py-3 font-semibold">매장</th>
                    <th class="text-left px-3 py-3 font-semibold">키워드</th>
                    <th class="text-right px-3 py-3 font-semibold">순위</th>
                    <th class="text-right px-3 py-3 font-semibold">N1</th>
                    <th class="text-right px-3 py-3 font-semibold">N2</th>
                    <th class="text-right px-3 py-3 font-semibold">N3</th>
                    <th class="text-right px-3 py-3 font-semibold">방문자</th>
                    <th class="text-right px-3 py-3 font-semibold">블로그</th>
                    <th class="text-center px-3 py-3 font-semibold">최근 분석</th>
                    <th class="text-right px-4 py-3 font-semibold">관리</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($analyses as $a)
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        <td class="px-4 py-3 text-right text-muted-soft" style="font-size:var(--fs-xs);">{{ $a->id }}</td>
                        <td class="px-3 py-3">
                            <a href="{{ route('console.place-store.show', $a) }}" class="text-ink font-semibold hover:underline" style="font-size:var(--fs-xs);">{{ $a->name }}</a>
                            <div class="text-muted-soft" style="font-size:var(--fs-xs);">ID {{ $a->place_id }} · {{ $a->cat ?: 'place' }}</div>
                        </td>
                        <td class="px-3 py-3 text-ink" style="font-size:var(--fs-xs);">{{ $a->keyword }}</td>
                        <td class="px-3 py-3 text-right text-ink" style="font-size:var(--fs-xs);">
                            {{ $a->rank && $a->rank < 300 ? $a->rank.'위' : '300+' }}
                        </td>
                        <td class="px-3 py-3 text-right text-ink" style="font-size:var(--fs-xs);">{{ $a->n1 === null ? '-' : round((float) $a->n1) }}</td>
                        <td class="px-3 py-3 text-right text-ink" style="font-size:var(--fs-xs);">{{ $a->n2 === null ? '-' : round((float) $a->n2) }}</td>
                        <td class="px-3 py-3 text-right text-ink font-semibold" style="font-size:var(--fs-xs);">{{ $a->n3 === null ? '-' : round((float) $a->n3) }}</td>
                        <td class="px-3 py-3 text-right text-muted" style="font-size:var(--fs-xs);">{{ $a->visitor_cnt === null ? '-' : number_format((int) $a->visitor_cnt) }}</td>
                        <td class="px-3 py-3 text-right text-muted" style="font-size:var(--fs-xs);">{{ $a->blog_cnt === null ? '-' : number_format((int) $a->blog_cnt) }}</td>
                        <td class="px-3 py-3 text-center text-muted-soft" style="font-size:var(--fs-xs);">{{ optional($a->updated_at)->format('m-d H:i') }}</td>
                        <td class="px-4 py-3 text-right text-nowrap">
                            <a href="{{ route('console.place-store.show', $a) }}" class="btn btn-secondary btn-sm">상세</a>
                            <button type="button" class="btn btn-secondary btn-sm ps-copy" data-url="{{ $a->shareUrl() }}">공유</button>
                            <form method="POST" action="{{ route('console.place-store.destroy', $a) }}" style="display:inline;" data-confirm="개별 분석을 삭제할까요?">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-error);">삭제</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="text-center" style="padding:56px 20px;color:var(--color-muted);">
                        <p style="font-size:var(--fs-xs);">저장된 개별 분석이 없습니다. 위 입력창에서 플레이스와 키워드를 넣고 분석을 시작하세요.</p>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('ps-form');
    if (form) {
        form.addEventListener('submit', function () {
            Swal.fire({
                title: '개별 분석 중',
                html: '<span style="font-size:var(--fs-xs);color:var(--color-muted);">상위 경쟁 데이터와 매장 리뷰 신호를 수집합니다. 잠시만 기다려주세요.</span>',
                allowOutsideClick: false,
                showConfirmButton: false,
                didOpen: function () { Swal.showLoading(); }
            });
        });
    }

    const placeEl = document.getElementById('ps-place');
    const infoEl = document.getElementById('ps-place-info');
    const resolveUrl = @json(route('console.rank.resolve'));
    let timer = null;
    let last = '';
    function resolvePlace() {
        const v = (placeEl.value || '').trim();
        if (v === '' || v === last) return;
        last = v;
        infoEl.textContent = '매장 정보 확인 중...';
        infoEl.style.color = 'var(--color-muted)';
        fetch(resolveUrl + '?place=' + encodeURIComponent(v), { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(d => {
                if (d && d.ok && d.place_name) {
                    infoEl.innerHTML = '<b style="color:var(--color-ink)">' + esc(d.place_name) + '</b>' + (d.category ? ' <span style="color:var(--color-muted-soft)">· ' + esc(d.category) + '</span>' : '') + (d.place_id ? ' <span style="color:var(--color-muted-soft)">· ID ' + esc(d.place_id) + '</span>' : '');
                    infoEl.style.color = 'var(--color-primary)';
                } else if (d && d.place_id) {
                    infoEl.textContent = 'ID ' + d.place_id + ' · 분석 시 매장명을 다시 확인합니다.';
                    infoEl.style.color = 'var(--color-muted)';
                } else {
                    infoEl.textContent = '플레이스를 찾지 못했습니다. URL 또는 ID를 확인하세요.';
                    infoEl.style.color = 'var(--color-muted-soft)';
                }
            })
            .catch(() => { infoEl.textContent = ''; });
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    if (placeEl) {
        placeEl.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(resolvePlace, 600);
        });
        placeEl.addEventListener('blur', resolvePlace);
        if (placeEl.value.trim() !== '') resolvePlace();
    }

    document.querySelectorAll('.ps-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const url = new URL(btn.dataset.url, location.origin).href;
            function done() {
                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: '공유 링크를 복사했습니다.', showConfirmButton: false, timer: 1500 });
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(done).catch(fallback);
            } else {
                fallback();
            }
            function fallback() {
                const ta = document.createElement('textarea');
                ta.value = url;
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); done(); } catch (e) { Swal.fire({ icon: 'info', title: '공유 링크', text: url }); }
                ta.remove();
            }
        });
    });
})();
</script>
@endsection
