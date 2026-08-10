@extends('admin.layout')
@section('page-title', '수집 상품')

@section('admin-content')
<x-console.page-head title="수집 상품" desc="지금까지 수집한 네이버 쇼핑 상품을 상품 기준으로 봅니다. 이미 수집된 판매자정보는 '정보 보기'로 확인할 수 있습니다." />

<div class="card p-4 mb-4">
    <form method="GET" action="{{ route('admin.shop-products') }}" class="flex items-center gap-2" style="flex-wrap:nowrap;overflow-x:auto;">
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
            <option value="y" @selected($talk === 'y')>톡톡 있는 상품</option>
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

    {{-- 판매자정보 '수집' UI 는 제거됐다(2026-08-10) — 캡차 자동풀이가 크롬 웹스토어 정책에
         걸려 확장에서 들어냈기 때문. 이미 수집된 정보의 '열람'만 남긴다. --}}
</div>

<div class="card">
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:var(--fs-sm);">
            <thead>
                <tr class="text-muted-soft" style="text-align:left;border-bottom:1px solid var(--color-hairline);">
                    <th style="padding:8px 6px;text-align:right;width:56px;">No</th>
                    <th style="padding:8px 6px;">상품명 <span class="text-muted-soft" style="font-weight:400;">/ 판매처 · 스토어ID</span></th>
                    <th style="padding:8px 6px;">노출 키워드</th>
                    <th style="padding:8px 6px;width:90px;">톡톡</th>
                    <th style="padding:8px 6px;width:84px;">수집일</th>
                    <th style="padding:8px 6px;width:150px;">판매자정보</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $p)
                    @php
                        $storeId = $p->store_id ?: '';
                        if ($storeId === '' && !empty($p->link) && preg_match('#(?:smartstore|brand)\.naver\.com/([^/]+)/#', $p->link, $m)) {
                            $storeId = $m[1];
                        }
                        $home = !empty($p->link) ? preg_replace('#/products/.*$#', '', $p->link) : null;
                    @endphp
                    @php $info = $storeId !== '' ? ($sellerInfoMap[$storeId] ?? null) : null; $hasInfo = (bool) $info; @endphp
                    <tr style="border-bottom:1px solid var(--color-hairline-soft);">
                        <td style="padding:7px 6px;text-align:right;" class="font-mono text-muted-soft">
                            {{ number_format($items->total() - ($items->firstItem() - 1) - $loop->index) }}
                        </td>
                        <td style="padding:7px 6px;max-width:460px;">
                            @if (!empty($p->link))
                                <a href="{{ $p->link }}" target="_blank" rel="noopener" class="text-ink font-semibold"
                                   style="text-decoration:none;" title="{{ $p->title }}">{{ $p->title }}</a>
                            @else
                                <span class="text-ink font-semibold">{{ $p->title }}</span>
                            @endif
                            @if (!empty($p->is_ad))
                                <span class="badge" style="font-size:var(--fs-xs);padding:1px 6px;">광고</span>
                            @endif
                            <div style="margin-top:3px;">
                                <span class="text-muted" style="font-size:var(--fs-xs);">{{ $p->mall_name ?: '—' }}</span>
                                @if ($storeId !== '')
                                    <span class="text-muted-soft" style="font-size:var(--fs-xs);">·</span>
                                    @if ($home)
                                        <a href="{{ $home }}" target="_blank" rel="noopener" class="font-mono"
                                           style="color:var(--color-primary);text-decoration:none;font-size:var(--fs-xs);" title="{{ $home }}">{{ $storeId }}</a>
                                    @else
                                        <span class="font-mono text-muted-soft" style="font-size:var(--fs-xs);">{{ $storeId }}</span>
                                    @endif
                                @endif
                            </div>
                        </td>
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
                        <td style="padding:7px 6px;">
                            <div class="flex items-center gap-1" style="flex-wrap:wrap;">
                                @if ($hasInfo)
                                    <button type="button" class="btn btn-primary btn-sm rf-cap-toggle" data-target="cap-{{ $loop->index }}">정보 보기</button>
                                @else
                                    <span class="text-muted-soft">—</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @if ($hasInfo)
                        <tr id="cap-{{ $loop->index }}" class="hidden">
                            <td colspan="6" style="padding:0;">
                                <div style="background:var(--color-surface-soft);padding:14px 16px;border-bottom:1px solid var(--color-hairline-soft);">
                                    <div class="text-muted" style="font-size:var(--fs-xs);margin-bottom:10px;">
                                        수집한 판매자정보 — 스토어 <span class="font-mono text-ink">{{ $storeId }}</span>
                                        <span class="text-muted-soft">· {{ $info->captured_at ? $info->captured_at->format('Y-m-d H:i') : '' }}</span>
                                    </div>
                                    <div class="card p-3" style="max-width:640px;">
                                        @php $rows = [['상호명', $info->biz_name], ['대표자', $info->representative], ['고객센터', $info->customer_phone], ['사업자등록번호', $info->biz_reg_no], ['통신판매업번호', $info->mail_order_no], ['e-mail', $info->email], ['사업장 소재지', $info->address]]; @endphp
                                        <table style="width:100%;font-size:var(--fs-sm);border-collapse:collapse;">
                                            @foreach ($rows as $r)
                                                <tr>
                                                    <th style="text-align:left;padding:4px 12px 4px 0;white-space:nowrap;vertical-align:top;font-weight:600;" class="text-muted">{{ $r[0] }}</th>
                                                    <td style="padding:4px 0;" class="text-ink">{{ $r[1] ?: '—' }}</td>
                                                </tr>
                                            @endforeach
                                        </table>
                                        @if ($info->seller_info_url)
                                            <a href="{{ $info->seller_info_url }}" target="_blank" rel="noopener" class="text-muted-soft"
                                               style="font-size:var(--fs-xs);display:inline-block;margin-top:8px;text-decoration:none;">원문 페이지 →</a>
                                        @endif
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="6" class="text-muted-soft text-center" style="padding:40px;">
                        수집된 상품이 없습니다. 키워드 상세에서 상품을 먼저 수집해 주세요.
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($items->hasPages())
    <div class="mt-4">{{ $items->links() }}</div>
@endif

<script>
    // 수집된 판매자정보 펼치기/접기
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.rf-cap-toggle');
        if (!btn) return;
        var row = document.getElementById(btn.getAttribute('data-target'));
        if (!row) return;
        var open = row.classList.toggle('hidden') === false;
        btn.classList.toggle('btn-primary', !open);
        btn.classList.toggle('btn-secondary', open);
    });
</script>
@endsection
