@extends('admin.layout')
@section('page-title', '수집 상품')

@section('admin-content')
<x-console.page-head title="수집 상품" desc="지금까지 수집된 쇼핑 상품 전체입니다 — 어느 키워드에 몇 위로 걸렸는지 함께 봅니다. 스마트스토어 노출분만 저장합니다." />

{{-- 필터 — 한 줄 고정(좁으면 가로 스크롤) --}}
<div class="card p-4 mb-4">
    <form method="GET" action="{{ route('admin.shop-products') }}" class="flex items-center gap-2" style="flex-wrap:nowrap;overflow-x:auto;">
        {{-- .input 은 width:100% 라 폭을 명시하지 않으면 뒤의 셀렉트를 전부 밀어낸다 --}}
        <input type="search" name="q" value="{{ $q }}" placeholder="상품명 검색" class="input flex-none"
               style="height:36px;width:220px;" autocomplete="off">

        <select name="mall" class="input flex-none" style="height:36px;width:170px;" onchange="this.form.submit()">
            <option value="">판매처 전체</option>
            @foreach ($malls as $name => $c)
                <option value="{{ $name }}" @selected($mall === (string) $name)>{{ $name }} ({{ number_format($c) }})</option>
            @endforeach
        </select>

        <select name="month" class="input flex-none" style="height:36px;width:130px;" onchange="this.form.submit()">
            <option value="">수집월 전체</option>
            @foreach ($months as $m)
                <option value="{{ $m }}" @selected($month === (int) $m)>{{ substr($m, 0, 4) }}-{{ substr($m, 4, 2) }}</option>
            @endforeach
        </select>

        <select name="ad" class="input flex-none" style="height:36px;width:110px;" onchange="this.form.submit()">
            <option value="">광고 전체</option>
            <option value="n" @selected($ad === 'n')>광고 제외</option>
            <option value="y" @selected($ad === 'y')>광고만</option>
        </select>

        <select name="talk" class="input flex-none" style="height:36px;width:130px;" onchange="this.form.submit()">
            <option value="">톡톡 전체</option>
            <option value="y" @selected($talk === 'y')>톡톡 있는 것만</option>
        </select>

        <select name="sort" class="input flex-none" style="height:36px;width:150px;" onchange="this.form.submit()">
            <option value="recent" @selected($sort === 'recent')>최근 수집순</option>
            <option value="kw" @selected($sort === 'kw')>노출 키워드 많은순</option>
            <option value="price_high" @selected($sort === 'price_high')>가격 높은순</option>
            <option value="price_low" @selected($sort === 'price_low')>가격 낮은순</option>
            <option value="title" @selected($sort === 'title')>상품명순</option>
        </select>

        <button type="submit" class="btn btn-primary btn-sm flex-none">검색</button>
        @if ($q !== '' || $mall !== '' || $ad !== '' || $talk !== '' || $month)
            <a href="{{ route('admin.shop-products') }}" class="btn btn-secondary btn-sm flex-none">초기화</a>
        @endif

        <span class="text-muted ml-auto flex-none" style="font-size:var(--fs-xs);">
            <b class="font-mono text-ink">{{ number_format($total) }}</b>개
        </span>
    </form>
</div>

<div class="card">
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:var(--fs-sm);">
            <thead>
                <tr class="text-muted-soft" style="text-align:left;border-bottom:1px solid var(--color-hairline);">
                    <th style="padding:8px 6px;text-align:right;width:56px;">No</th>
                    <th style="padding:8px 6px;">상품명</th>
                    <th style="padding:8px 6px;">판매처</th>
                    <th style="padding:8px 6px;width:130px;">스토어ID</th>
                    <th style="padding:8px 6px;">노출 키워드</th>
                    <th style="padding:8px 6px;text-align:right;width:64px;">최고순위</th>
                    <th style="padding:8px 6px;text-align:right;width:90px;">가격</th>
                    <th style="padding:8px 6px;width:90px;">톡톡</th>
                    <th style="padding:8px 6px;width:84px;">수집일</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $p)
                    <tr style="border-bottom:1px solid var(--color-hairline-soft);">
                        {{-- No — 전체에서 역순(첫 행이 가장 큰 번호). 페이지를 넘겨도 이어진다 --}}
                        <td style="padding:7px 6px;text-align:right;" class="font-mono text-muted-soft">
                            {{ number_format($items->total() - ($items->firstItem() - 1) - $loop->index) }}
                        </td>
                        <td style="padding:7px 6px;max-width:420px;">
                            @if (!empty($p->link))
                                <a href="{{ $p->link }}" target="_blank" rel="noopener" class="text-ink font-semibold"
                                   style="text-decoration:none;" title="{{ $p->title }}">{{ $p->title }}</a>
                            @else
                                <span class="text-ink font-semibold">{{ $p->title }}</span>
                            @endif
                            @if (!empty($p->is_ad))
                                <span class="badge" style="font-size:var(--fs-xs);padding:1px 6px;">광고</span>
                            @endif
                        </td>
                        <td style="padding:7px 6px;" class="text-muted">{{ $p->mall_name ?: '—' }}</td>

                        {{-- 스토어 핸들 — 누르면 그 스토어 홈으로(상품 URL 에서 /products/ 앞이 곧 스토어 주소) --}}
                        <td style="padding:7px 6px;">
                            @if (!empty($p->store_id))
                                @php $__home = $p->link ? preg_replace('#/products/.*$#', '', $p->link) : null; @endphp
                                @if ($__home)
                                    <a href="{{ $__home }}" target="_blank" rel="noopener" class="font-mono"
                                       style="color:var(--color-primary);text-decoration:none;" title="{{ $__home }}">{{ $p->store_id }}</a>
                                @else
                                    <span class="font-mono text-muted">{{ $p->store_id }}</span>
                                @endif
                            @else
                                <span class="text-muted-soft">—</span>
                            @endif
                        </td>

                        {{-- 이 상품이 걸린 키워드 — 누르면 그 키워드 상세로 --}}
                        <td style="padding:7px 6px;">
                            @php $kws = $kwMap[$p->product_key] ?? []; @endphp
                            @forelse (array_slice($kws, 0, 3) as $k)
                                <a href="{{ route('admin.keyword-browse.detail', ['keyword' => $k['keyword']]) }}"
                                   style="color:var(--color-primary);text-decoration:none;" title="{{ $k['keyword'] }} {{ $k['rnk'] }}위">
                                    {{ $k['keyword'] }}<span class="text-muted-soft font-mono">({{ $k['rnk'] }})</span></a>@if (!$loop->last)<span class="text-muted-soft">,</span>@endif
                            @empty
                                <span class="text-muted-soft">—</span>
                            @endforelse
                            @if (count($kws) > 3)
                                <span class="text-muted-soft" title="{{ collect($kws)->pluck('keyword')->implode(', ') }}">+{{ count($kws) - 3 }}</span>
                            @endif
                        </td>

                        <td style="padding:7px 6px;text-align:right;" class="font-mono text-muted">{{ $p->best_rnk ?: '—' }}</td>
                        <td style="padding:7px 6px;text-align:right;" class="font-mono">{{ $p->price ? number_format($p->price) : '—' }}</td>
                        <td style="padding:7px 6px;">
                            @if (!empty($p->talk_id))
                                <a href="https://talk.naver.com/ct/{{ $p->talk_id }}" target="_blank" rel="noopener"
                                   class="font-mono" style="color:var(--color-primary);text-decoration:none;" title="톡톡 열기">{{ $p->talk_id }}</a>
                            @else
                                <span class="text-muted-soft">—</span>
                            @endif
                        </td>
                        <td style="padding:7px 6px;" class="text-muted-soft font-mono"
                            title="{{ $p->last_at ? \Carbon\Carbon::parse($p->last_at)->format('Y-m-d H:i') : '' }}">
                            {{ $p->last_at ? \Carbon\Carbon::parse($p->last_at)->format('m-d') : '—' }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-muted-soft text-center" style="padding:40px;">
                        수집된 상품이 없습니다. 키워드 탐색에서 상품을 수집해 주세요.
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($items->hasPages())
    <div class="mt-4">{{ $items->links() }}</div>
@endif
@endsection
