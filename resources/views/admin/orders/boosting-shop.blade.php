@extends('admin.layout')
@section('page-title', '부스팅샵 주문')

@section('admin-content')
@php
    // 전송값 초안 — 다시 그릴 때(검증 실패)는 방금 입력한 값을 우선한다
    $v = fn ($k) => old($k, $draft[$k] ?? '');
    $profile = $draft['profile'] ?? [];
@endphp
<x-console.page-head title="부스팅샵 주문"
    desc="주문 {{ $order->order_no }} 을(를) 부스팅샵 플레이스 주문으로 접수합니다 · 전송값을 확인·보완한 뒤 보내세요" />

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

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">부스팅샵 상품 <span style="color:var(--color-error);">*</span></span>
                {{-- product_no 하나로 유입/저장과 등급이 모두 결정된다(부스팅샵 문서) — 주문 상품에 맞춰 자동 선택 --}}
                <select name="product_no" required class="input" style="font-size:var(--fs-xs);">
                    @foreach ($products as $svc)
                        <optgroup label="{{ $svc['label'] }}">
                            @foreach ($svc['grades'] as $no => $grade)
                                <option value="{{ $no }}" {{ (string) $v('product_no') === (string) $no ? 'selected' : '' }}>{{ $svc['label'] }} · {{ $grade }} ({{ $no }})</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <span class="text-muted-soft" style="font-size:var(--fs-xs);">주문 상품에 맞춰 자동 선택 — 등급이 다르면 바꾸세요 (프리미엄은 스마트콜 URL 필수)</span>
            </label>
        </div>

        {{-- 플레이스 주소 + 상호명 한 줄(2026-08-27 사용자 요청) --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <label class="flex flex-col gap-1 sm:col-span-2">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">플레이스 주소 <span style="color:var(--color-error);">*</span></span>
                <input name="link" value="{{ $v('link') }}" required type="url" class="input" style="font-size:var(--fs-xs);" placeholder="https://m.place.naver.com/place/1011101134/home">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">상호명 <span style="color:var(--color-error);">*</span></span>
                <input name="product_name" value="{{ $v('product_name') }}" required class="input" style="font-size:var(--fs-xs);" placeholder="플레이스에 등록된 업체명">
                <span class="text-muted-soft" style="font-size:var(--fs-xs);">{{ ($profile['name'] ?? '') !== '' ? '플레이스에서 자동 수집됨' : '자동 수집 실패 — 직접 입력' }}</span>
            </label>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">순위체크 키워드 <span style="color:var(--color-error);">*</span></span>
                <input name="keyword" value="{{ $v('keyword') }}" required class="input" style="font-size:var(--fs-xs);">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">추가 키워드 2</span>
                <input name="keyword2" value="{{ $v('keyword2') }}" class="input" style="font-size:var(--fs-xs);">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">추가 키워드 3</span>
                <input name="keyword3" value="{{ $v('keyword3') }}" class="input" style="font-size:var(--fs-xs);">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">플레이스 고유번호(pid)</span>
                <input name="pid" value="{{ $v('pid') }}" inputmode="numeric" class="input font-mono" style="font-size:var(--fs-xs);">
            </label>
        </div>

        <div class="flex flex-col gap-1">
            <div class="flex items-center justify-between gap-2 flex-wrap">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">유입 키워드 <span style="color:var(--color-error);">*</span></span>
                {{-- 플레이스명·지역·업종 조합 중 통합검색에 실제로 노출되는 키워드만 골라 채운다(2026-08-27) --}}
                <button type="button" id="kw-suggest" class="btn btn-secondary btn-sm" style="height:26px;padding:0 10px;font-size:var(--fs-xs);">키워드 자동 추천</button>
            </div>
            <textarea name="search_keywords" required class="input" style="font-size:var(--fs-xs);line-height:1.6;height:150px;">{{ $v('search_keywords') }}</textarea>
            {{-- 추천 결과 — 키워드마다 통합검색 플레이스 영역 노출 순위를 옆에 붙여 보여준다(2026-08-27) --}}
            @php $savedRanks = array_filter((array) ($draft['keyword_ranks'] ?? []), fn ($r) => (int) $r > 0); @endphp
            <input type="hidden" name="keyword_ranks" id="kw-ranks" value="{{ json_encode($draft['keyword_ranks'] ?? [], JSON_UNESCAPED_UNICODE) }}">
            <div id="kw-list" class="flex flex-wrap gap-1.5" style="{{ $savedRanks ? '' : 'display:none;' }}">
                @foreach ($savedRanks as $kw => $rk)
                    {{-- 클릭하면 그 키워드로 네이버 통합검색을 새 창에 열어 바로 눈으로 확인(2026-08-27) --}}
                    <a href="https://m.search.naver.com/search.naver?sm=mtp_hty.top&where=m&query={{ urlencode($kw) }}" target="_blank" rel="noopener nofollow"
                       class="badge border border-hairline hover:underline" style="font-size:var(--fs-xs);padding:3px 10px;" title="네이버에서 이 키워드로 검색해 확인">{{ $kw }} <b class="font-mono" style="color:var(--color-success);">{{ $rk }}위</b> ↗</a>
                @endforeach
            </div>
            <span id="kw-status" class="text-muted-soft" style="font-size:var(--fs-xs);">한 줄에 하나씩(쉼표도 가능) · 1~30개 — 미션 참여자가 검색할 키워드입니다. [키워드 자동 추천]은 <b>실제 검색 화면의 플레이스 영역에 뜨는 키워드만</b>(더보기 포함 · 상위 20위) 노출 순위와 함께 골라 채웁니다.</span>
            <div id="kw-missed" class="text-muted-soft" style="font-size:var(--fs-xs);display:none;"></div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">1일 수량 <span style="color:var(--color-error);">*</span></span>
                <input name="day_quantity" value="{{ $v('day_quantity') }}" required inputmode="numeric" class="input font-mono" style="font-size:var(--fs-xs);">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">시작일 <span style="color:var(--color-error);">*</span></span>
                <input name="fr_date" value="{{ $v('fr_date') }}" required type="date" class="input font-mono" style="font-size:var(--fs-xs);">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">종료일 <span style="color:var(--color-error);">*</span></span>
                <input name="to_date" value="{{ $v('to_date') }}" required type="date" class="input font-mono" style="font-size:var(--fs-xs);">
            </label>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">스마트콜 URL</span>
                <input name="smartcall_url" value="{{ $v('smartcall_url') }}" type="url" class="input" style="font-size:var(--fs-xs);" placeholder="프리미엄 상품 필수">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">업체 전화번호</span>
                <input name="place_tel" value="{{ $v('place_tel') }}" class="input" style="font-size:var(--fs-xs);">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">대표 사진 URL</span>
                <input name="image_url" value="{{ $v('image_url') }}" type="url" class="input" style="font-size:var(--fs-xs);">
            </label>
        </div>

        <div class="flex items-center gap-2 justify-end" style="border-top:1px solid var(--color-hairline-soft);padding-top:14px;">
            <span id="kw-saved" class="text-muted-soft" style="font-size:var(--fs-xs);margin-right:auto;">{{ ($draft['saved_at'] ?? '') !== '' ? '저장됨 '.$draft['saved_at'] : '' }}</span>
            <a href="{{ route('admin.orders.show', $order) }}" class="btn btn-ghost btn-sm">취소</a>
            {{-- 접수하지 않고 지금 값만 주문에 저장 — 다시 열면 이어서 작업(2026-08-27) --}}
            <button type="button" id="kw-save" class="btn btn-secondary btn-sm">저장</button>
            <button type="submit" class="btn btn-primary btn-sm" @disabled(! $configured)>부스팅샵으로 주문</button>
        </div>
    </form>

    {{-- 주문 원본 + 플레이스 수집 정보 — 전송값이 실제 업체와 맞는지 눈으로 대조 --}}
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

        @if (($profile['name'] ?? '') !== '')
            <div class="text-muted font-semibold mt-2" style="font-size:var(--fs-xs);border-top:1px solid var(--color-hairline-soft);padding-top:10px;">플레이스 수집 정보</div>
            <div class="flex flex-col gap-2" style="font-size:var(--fs-xs);">
                <div class="flex justify-between gap-3"><span class="text-muted">상호명</span><span class="text-ink text-right">{{ $profile['name'] }}</span></div>
                @if (($profile['category'] ?? '') !== '')
                    <div class="flex justify-between gap-3"><span class="text-muted">업종</span><span class="text-ink text-right">{{ $profile['category'] }}</span></div>
                @endif
                @if (($profile['address'] ?? '') !== '')
                    <div class="flex justify-between gap-3"><span class="text-muted">주소</span><span class="text-ink text-right" style="word-break:break-all;">{{ $profile['address'] }}</span></div>
                @endif
                @if (($profile['phone'] ?? '') !== '')
                    <div class="flex justify-between gap-3"><span class="text-muted">전화</span><span class="text-ink text-right font-mono">{{ $profile['phone'] }}</span></div>
                @endif
            </div>
            <p class="text-muted-soft" style="font-size:var(--fs-xs);">상호명·전화는 플레이스에서 자동으로 가져왔습니다. 업종·주소는 <b class="text-muted">유입 키워드 추천</b>의 재료입니다.</p>
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
            접수되면 부스팅샵 <b class="text-muted">적립금이 차감</b>되고 주문이 <b class="text-muted">진행중</b>으로 바뀝니다.
            결과는 주문 상세의 <b class="text-muted">외부 발주 현황</b>에 부스팅샵 주문번호와 함께 남습니다.
        </p>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.getElementById('kw-suggest')?.addEventListener('click', async function () {
    const btn = this;
    const ta = document.querySelector('[name="search_keywords"]');
    const status = document.getElementById('kw-status');
    const missed = document.getElementById('kw-missed');
    const label = btn.textContent;

    btn.disabled = true;
    btn.textContent = '순위 확인 중…';
    status.textContent = '실제 검색 화면에서 플레이스 노출 순위를 확인하는 중입니다 — 1~2분 걸릴 수 있습니다.';
    missed.style.display = 'none';

    try {
        const res = await fetch(@json(route('admin.orders.boosting-shop.keywords', $order)), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value,
            },
            body: JSON.stringify({
                link: document.querySelector('[name="link"]').value,
                current: ta.value,
            }),
        });
        const d = await res.json();
        if (!res.ok || !d.ok) throw new Error(d.message || '추천에 실패했습니다.');

        if (d.failed) {
            status.textContent = '순위 확인에 실패했습니다(수집 브라우저가 이미 사용 중이거나 오류) — 잠시 뒤 다시 시도하세요. 확인된 것까지만 채웠습니다.';
        }
        if (d.exposed.length) {
            ta.value = d.exposed.map(function (x) { return x.keyword; }).join('\n');
            renderRanks(d.exposed);
            if (!d.failed) {
                status.innerHTML = '후보 <b>' + d.checked + '개</b> 중 <b>' + d.exposed.length + '개</b>가 플레이스 영역에 노출됩니다 — 아래 순위를 확인하고 [저장]을 누르세요.';
            }
        } else if (!d.failed) {
            status.innerHTML = '후보 <b>' + d.checked + '개</b>를 확인했지만 플레이스 영역에 뜨는 키워드가 없었습니다 — 직접 입력하세요.';
        }
        if (d.missed.length) {
            missed.textContent = '플레이스 영역에 없음(제외): ' + d.missed.join(', ');
            missed.style.display = '';
        }
    } catch (e) {
        status.textContent = '오류: ' + e.message;
    } finally {
        btn.disabled = false;
        btn.textContent = label;
    }
});

/** 추천 결과를 키워드 옆 순위 배지로 그린다 + 저장용 hidden 갱신. */
function renderRanks(exposed) {
    const list = document.getElementById('kw-list');
    const hidden = document.getElementById('kw-ranks');
    const map = {};
    list.innerHTML = '';
    exposed.forEach(function (x) {
        map[x.keyword] = x.rank;
        const el = document.createElement('a');
        el.className = 'badge border border-hairline hover:underline';
        el.style.cssText = 'font-size:var(--fs-xs);padding:3px 10px;';
        el.href = 'https://m.search.naver.com/search.naver?sm=mtp_hty.top&where=m&query=' + encodeURIComponent(x.keyword);
        el.target = '_blank';
        el.rel = 'noopener nofollow';
        el.title = '네이버에서 이 키워드로 검색해 확인';
        el.innerHTML = x.keyword + ' <b class="font-mono" style="color:var(--color-success);">' + x.rank + '위</b> ↗';
        list.appendChild(el);
    });
    list.style.display = exposed.length ? '' : 'none';
    hidden.value = JSON.stringify(map);
}

/** 저장 — 접수하지 않고 지금 입력값과 순위를 주문에 남긴다(확인창 없이 바로 저장). */
document.getElementById('kw-save')?.addEventListener('click', async function () {
    const btn = this;
    const form = btn.closest('form');
    const saved = document.getElementById('kw-saved');
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
