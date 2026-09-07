@extends('admin.layout')
@section('page-title', '부스팅샵 쇼핑 주문')

@section('admin-content')
@php
    // 전송값 초안 — 다시 그릴 때(검증 실패)는 방금 입력한 값을 우선한다
    $v = fn ($k) => old($k, $draft[$k] ?? '');
    $collected = $draft['collected'] ?? ['landing_urls' => [], 'is_store' => false];
    $collectedUrls = (array) ($collected['landing_urls'] ?? []);
@endphp
<x-console.page-head title="부스팅샵 쇼핑 주문"
    desc="주문 {{ $order->order_no }} 을(를) 부스팅샵 쇼핑 주문으로 접수합니다 · 랜딩 URL 은 여러 개를 한 번에 보내 하루씩 돌려 씁니다" />

@if ($errors->any())
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">
        @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
@endif

@if (! $configured)
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">
        부스팅샵 API 키가 설정되지 않았습니다 — 운영 서버 <b>.env</b> 의 <b>BOOSTINGSHOP_API_KEY</b> 를 채우고 <b>config:cache</b> 를 다시 실행하세요.
    </div>
@endif

@if ($sentDispatch)
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-success) 8%,var(--color-canvas));color:var(--color-success);font-size:var(--fs-xs);">
        이미 부스팅샵으로 접수된 주문입니다 — {{ $sentDispatch->response }}<br>
        다시 넣으려면 <a href="{{ route('admin.orders.show', $order) }}" class="underline">주문 상세</a>의 외부 발주 현황에서 이 발주를 취소하세요.
    </div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    {{-- 전송값 --}}
    <form method="POST" action="{{ route('admin.orders.boosting-shop.place', $order) }}" class="card p-6 lg:col-span-2 flex flex-col gap-4"
          data-confirm="부스팅샵으로 주문할까요?" data-confirm-text="접수되면 부스팅샵 적립금이 차감됩니다. 전송값을 다시 한 번 확인하세요." data-confirm-ok="주문">
        @csrf
        <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">부스팅샵 전송값</div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">부스팅샵 상품번호 <span style="color:var(--color-error);">*</span></span>
                {{-- 쇼핑은 등급표가 공개돼 있지 않다 — 주문 화면 주소의 마지막 숫자를 넣고, 성공하면 상품에 기억된다 --}}
                <input name="product_no" value="{{ $v('product_no') }}" required inputmode="numeric" class="input font-mono" style="font-size:var(--fs-xs);" placeholder="57">
                <span class="text-muted-soft" style="font-size:var(--fs-xs);">부스팅샵 주문 화면 주소 <b class="font-mono">/ads/new/shopping/13/<b class="text-muted">57</b></b> 의 마지막 숫자</span>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">검색 키워드 <span style="color:var(--color-error);">*</span></span>
                <input name="keyword" value="{{ $v('keyword') }}" required class="input" style="font-size:var(--fs-xs);">
                <span class="text-muted-soft" style="font-size:var(--fs-xs);">노출시키려는 핵심 키워드</span>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">네이버 쇼핑 MID</span>
                <input name="mid" value="{{ $v('mid') }}" inputmode="numeric" class="input font-mono" style="font-size:var(--fs-xs);">
                <span class="text-muted-soft" style="font-size:var(--fs-xs);">
                    @if ($collected['is_store'] ?? false)
                        <b style="color:var(--color-error);">스마트스토어는 MID 필수</b> — URL 의 숫자는 스토어 내부 상품번호라 다릅니다(비우면 실적이 안 잡힐 수 있음)
                    @else
                        가격비교·자사몰은 URL 숫자가 곧 MID — 자동으로 채웠습니다
                    @endif
                </span>
            </label>
        </div>

        <label class="flex flex-col gap-1">
            <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">상품 URL <span style="color:var(--color-error);">*</span></span>
            <input name="product_url" value="{{ $v('product_url') }}" required type="url" class="input" style="font-size:var(--fs-xs);" placeholder="https://smartstore.naver.com/store/products/1234567890">
            <span class="text-muted-soft" style="font-size:var(--fs-xs);">스마트스토어 · 브랜드스토어 · 가격비교(catalog) · 자사몰 주소만 가능 — 상품 유형은 부스팅샵이 URL 로 판별합니다</span>
        </label>

        <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
            <label class="flex flex-col gap-1 sm:col-span-2">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">상품명 <span style="color:var(--color-error);">*</span></span>
                <input name="product_name" value="{{ $v('product_name') }}" required class="input" style="font-size:var(--fs-xs);">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">상점명 <span style="color:var(--color-error);">*</span></span>
                <input name="mall_name" value="{{ $v('mall_name') }}" required class="input" style="font-size:var(--fs-xs);">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">판매가 <span style="color:var(--color-error);">*</span></span>
                <input name="amount" value="{{ $v('amount') }}" required inputmode="numeric" class="input font-mono" style="font-size:var(--fs-xs);">
            </label>
        </div>

        <div class="flex flex-col gap-1">
            <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">상품 사진 URL <span style="color:var(--color-error);">*</span></span>
            <div class="flex items-start gap-3">
                <input name="image_url" id="bs-image" value="{{ $v('image_url') }}" required type="url" class="input flex-1" style="font-size:var(--fs-xs);">
                {{-- 썸네일 미리보기 — 수집값이 실제 상품 사진인지 눈으로 대조 --}}
                <img id="bs-image-preview" src="{{ $v('image_url') }}" alt="" class="rounded-md border border-hairline"
                     style="width:52px;height:52px;object-fit:cover;{{ $v('image_url') ? '' : 'display:none;' }}">
            </div>
        </div>

        {{-- 랜딩 URL 목록 — 이번 요청의 핵심(2026-09-07). 생성된 Short URL 을 전부 보내 부스팅샵이 하루씩 돌려 쓴다 --}}
        <div class="flex flex-col gap-1">
            <div class="flex items-center justify-between gap-2 flex-wrap">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">랜딩 URL <span style="color:var(--color-error);">*</span></span>
                <button type="button" id="bs-reload-urls" class="btn btn-secondary btn-sm" style="height:26px;padding:0 10px;font-size:var(--fs-xs);"
                        @disabled(! $collectedUrls)>Short URL 다시 불러오기 ({{ count($collectedUrls) }})</button>
            </div>
            <textarea name="landing_urls" id="bs-urls" required class="input font-mono" style="font-size:var(--fs-xs);line-height:1.6;height:150px;">{{ $v('landing_urls') }}</textarea>
            <span id="bs-urls-status" class="text-muted-soft" style="font-size:var(--fs-xs);">
                한 줄에 하나씩 · 1~{{ \App\Domain\Order\BoostingShopClient::SHOPPING_MAX_LANDING_URLS }}개 — 주문에 연결된 유입키워드 분석의 Short URL 을 자동으로 채웠습니다.
            </span>
            {{-- 순환 미리보기 — 시작일부터 하루에 하나씩, 목록 끝에 닿으면 처음으로 --}}
            <div id="bs-rotation" class="flex flex-wrap gap-1.5 mt-1" style="display:none;"></div>
        </div>

        <div class="flex flex-col gap-1">
            <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">정답 태그 <span style="color:var(--color-error);">*</span></span>
            <textarea name="tags" id="bs-tags" required class="input" style="font-size:var(--fs-xs);line-height:1.6;height:80px;">{{ $v('tags') }}</textarea>
            <span id="bs-tags-status" class="text-muted-soft" style="font-size:var(--fs-xs);">
                쉼표 또는 한 줄에 하나씩 · 1~{{ \App\Domain\Order\BoostingShopClient::SHOPPING_MAX_TAGS }}개 — <b class="text-muted">보내는 순서가 그대로 태그 번호</b>가 되고, 미션에서 "N번째 태그"를 묻습니다. 상품 상세에서 실제로 보이는 값이어야 합니다.
            </span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">1일 수량 <span style="color:var(--color-error);">*</span></span>
                <input name="day_quantity" value="{{ $v('day_quantity') }}" required inputmode="numeric" class="input font-mono" style="font-size:var(--fs-xs);">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">시작일 <span style="color:var(--color-error);">*</span></span>
                <input name="fr_date" id="bs-fr-date" value="{{ $v('fr_date') }}" required type="date" class="input font-mono" style="font-size:var(--fs-xs);">
                <span class="text-muted-soft" style="font-size:var(--fs-xs);">오늘 이후만 가능(부스팅샵 규칙)</span>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">종료일 <span style="color:var(--color-error);">*</span></span>
                <input name="to_date" id="bs-to-date" value="{{ $v('to_date') }}" required type="date" class="input font-mono" style="font-size:var(--fs-xs);">
            </label>
        </div>

        <div class="flex items-center gap-2 justify-end" style="border-top:1px solid var(--color-hairline-soft);padding-top:14px;">
            <span id="bs-saved" class="text-muted-soft" style="font-size:var(--fs-xs);margin-right:auto;">{{ ($draft['saved_at'] ?? '') !== '' ? '저장됨 '.$draft['saved_at'] : '' }}</span>
            <a href="{{ route('admin.orders.show', $order) }}" class="btn btn-ghost btn-sm">취소</a>
            {{-- 접수하지 않고 지금 값만 주문에 저장 — 다시 열면 이어서 작업 --}}
            <button type="button" id="bs-save" class="btn btn-secondary btn-sm">저장</button>
            <button type="submit" class="btn btn-primary btn-sm" @disabled(! $configured)>부스팅샵으로 주문</button>
        </div>
    </form>

    {{-- 주문 원본 — 전송값이 주문 입력과 맞는지 눈으로 대조 --}}
    <div class="card p-6 flex flex-col gap-3" style="height:fit-content;">
        <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">주문 정보</div>
        <div class="flex flex-col gap-2" style="font-size:var(--fs-xs);">
            <div class="flex justify-between gap-2"><span class="text-muted">주문번호</span>
                <a href="{{ route('admin.orders.show', $order) }}" class="text-accent hover:underline font-mono">{{ $order->order_no }} ↗</a></div>
            <div class="flex justify-between gap-2"><span class="text-muted">상품</span><span class="text-ink text-right">{{ $order->product?->title ?? '(삭제된 상품)' }}</span></div>
            <div class="flex justify-between gap-2"><span class="text-muted">주문자</span><span class="text-ink text-right">{{ $order->orderer_name }} · {{ $order->orderer_contact }}</span></div>
            <div class="flex justify-between gap-2"><span class="text-muted">수량 · 기간</span>
                <span class="text-ink font-mono">{{ number_format($order->quantity) }}@if ($order->days) × {{ $order->days }}일 @endif</span></div>
            <div class="flex justify-between gap-2"><span class="text-muted">상태</span><span class="text-ink">{{ \App\Models\MarketingOrder::STATUSES[$order->status] ?? $order->status }}</span></div>
        </div>

        <div class="text-muted font-semibold mt-2" style="font-size:var(--fs-xs);border-top:1px solid var(--color-hairline-soft);padding-top:10px;">유입키워드 분석</div>
        <div class="flex flex-col gap-2" style="font-size:var(--fs-xs);">
            <div class="flex justify-between gap-3"><span class="text-muted">연결된 분석</span>
                <span class="text-ink font-mono">{{ $order->shopKeywordAnalyses()->count() }}건</span></div>
            <div class="flex justify-between gap-3"><span class="text-muted">생성된 Short URL</span>
                <span class="text-ink font-mono">{{ count($collectedUrls) }}개</span></div>
        </div>
        @if (! $collectedUrls)
            <p class="text-muted-soft" style="font-size:var(--fs-xs);color:var(--color-error);">
                Short URL 이 아직 없습니다 — 주문 상세의 유입키워드 분석에서 Short URL 을 먼저 생성하세요. 랜딩 URL 은 최소 1개가 필요합니다.
            </p>
        @endif

        @if ($order->product?->fields->isNotEmpty())
            <div class="text-muted font-semibold mt-2" style="font-size:var(--fs-xs);border-top:1px solid var(--color-hairline-soft);padding-top:10px;">주문 입력값</div>
            <div class="flex flex-col gap-2" style="font-size:var(--fs-xs);">
                @foreach ($order->product->fields->where('is_active', true) as $f)
                    @php $fval = $order->field_values[$f->field_key] ?? null; @endphp
                    <div class="flex justify-between gap-3">
                        <span class="text-muted" style="white-space:nowrap;">{{ $f->label }}</span>
                        <span class="text-ink text-right" style="word-break:break-all;">{{ is_array($fval) ? implode(', ', $fval) : ($fval ?: '—') }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        <p class="text-muted-soft mt-2" style="font-size:var(--fs-xs);border-top:1px solid var(--color-hairline-soft);padding-top:10px;">
            랜딩 URL 은 <b class="text-muted">시작일부터 하루에 하나씩</b> 순서대로 쓰이고, 목록 끝에 닿으면 처음으로 돌아갑니다.
            접수되면 부스팅샵 <b class="text-muted">적립금이 차감</b>되고 주문이 <b class="text-muted">진행중</b>으로 바뀝니다.
        </p>
    </div>
</div>
@endsection

@push('scripts')
<script>
const bsCollected = @json($collectedUrls);

/** 입력된 랜딩 URL 목록 — 줄바꿈·쉼표·공백 구분(서버 파싱과 같은 규칙). */
function bsUrls() {
    return document.getElementById('bs-urls').value.split(/[\r\n,\s]+/).map(s => s.trim()).filter(Boolean)
        .filter((u, i, a) => a.indexOf(u) === i);
}

/** URL 개수와 시작일 기준 순환(앞 7일)을 보여준다 — 며칠에 어떤 주소가 열리는지 확인용. */
function bsRenderRotation() {
    const urls = bsUrls();
    const status = document.getElementById('bs-urls-status');
    const box = document.getElementById('bs-rotation');
    const bad = urls.filter(u => !/^https?:\/\//i.test(u));

    status.innerHTML = '한 줄에 하나씩 · <b class="text-muted">' + urls.length + '개</b>'
        + (urls.length ? ' — 시작일부터 하루에 하나씩 순서대로 쓰고, ' + urls.length + '일마다 처음으로 돌아갑니다.' : ' — 최소 1개가 필요합니다.')
        + (bad.length ? ' <b style="color:var(--color-error);">URL 형식이 아닌 값 ' + bad.length + '개</b>' : '');

    const from = document.getElementById('bs-fr-date').value;
    if (!urls.length || !from) { box.style.display = 'none'; return; }

    const start = new Date(from + 'T00:00:00');
    box.innerHTML = '';
    for (let i = 0; i < Math.min(7, Math.max(urls.length, 3)); i++) {
        const d = new Date(start.getTime() + i * 86400000);
        const el = document.createElement('span');
        el.className = 'badge border border-hairline';
        el.style.cssText = 'font-size:var(--fs-xs);padding:3px 10px;';
        el.title = urls[i % urls.length];
        el.innerHTML = (d.getMonth() + 1) + '/' + d.getDate() + ' <b class="font-mono">' + ((i % urls.length) + 1) + '번</b>';
        box.appendChild(el);
    }
    box.style.display = '';
}

/** 태그 개수 표기 — 순번이 곧 정답 번호라 개수를 눈으로 확인하고 보낸다. */
function bsRenderTags() {
    const tags = document.getElementById('bs-tags').value.split(/[\r\n,]+/).map(s => s.trim()).filter(Boolean);
    document.getElementById('bs-tags-status').innerHTML = '쉼표 또는 한 줄에 하나씩 · <b class="text-muted">' + tags.length + '개</b>'
        + ' — <b class="text-muted">보내는 순서가 그대로 태그 번호</b>가 되고, 미션에서 "N번째 태그"를 묻습니다.';
}

document.getElementById('bs-urls').addEventListener('input', bsRenderRotation);
document.getElementById('bs-fr-date').addEventListener('change', bsRenderRotation);
document.getElementById('bs-tags').addEventListener('input', bsRenderTags);
document.getElementById('bs-image').addEventListener('input', function () {
    const img = document.getElementById('bs-image-preview');
    img.src = this.value;
    img.style.display = this.value ? '' : 'none';
});
bsRenderRotation();
bsRenderTags();

/** 분석에서 생성된 Short URL 을 다시 불러온다 — 링크를 새로 만든 뒤 목록을 갱신할 때. */
document.getElementById('bs-reload-urls')?.addEventListener('click', function () {
    if (!bsCollected.length) return;
    document.getElementById('bs-urls').value = bsCollected.join('\n');
    bsRenderRotation();
});

/** 저장 — 접수하지 않고 지금 입력값을 주문에 남긴다(확인창 없이 바로 저장). */
document.getElementById('bs-save')?.addEventListener('click', async function () {
    const btn = this;
    const form = btn.closest('form');
    const saved = document.getElementById('bs-saved');
    const label = btn.textContent;

    btn.disabled = true;
    btn.textContent = '저장 중…';
    try {
        const res = await fetch(@json(route('admin.orders.boosting-shop.save', $order)), {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value },
            body: new FormData(form),
        });
        const d = await res.json();
        if (!res.ok || !d.ok) throw new Error(d.message || '저장에 실패했습니다.');
        saved.textContent = '저장됨 ' + d.saved_at;
        Swal.fire({ icon: 'success', title: '저장했습니다', timer: 1200, showConfirmButton: false });
    } catch (e) {
        Swal.fire({ icon: 'error', title: '저장 실패', text: e.message });
    } finally {
        btn.disabled = false;
        btn.textContent = label;
    }
});
</script>
@endpush
