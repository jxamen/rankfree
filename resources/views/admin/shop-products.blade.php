@extends('admin.layout')
@section('page-title', '수집 상품')

@section('admin-content')
<x-console.page-head title="수집 상품" desc="지금까지 수집한 네이버 쇼핑 상품을 상품 기준으로 봅니다. 스마트스토어/브랜드스토어 상품의 판매자정보 퀴즈 이미지를 확장으로 수집할 수 있습니다." />

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

    <div class="mt-3 flex items-center gap-2 flex-wrap">
        <button type="button" id="rf-seller-captcha-all" class="btn btn-primary btn-sm">현재 페이지 판매자정보 수집</button>
        <select id="rf-seller-captcha-conc" class="input" style="height:32px;width:108px;">
            <option value="1">동시 1개</option>
            <option value="2">동시 2개</option>
            <option value="3" selected>동시 3개</option>
            <option value="4">동시 4개</option>
        </select>
        <button type="button" id="rf-seller-captcha-stop" class="btn btn-secondary btn-sm" hidden>중단</button>
        <span id="rf-seller-captcha-msg" class="text-muted" style="font-size:var(--fs-xs);">상품별 수집 버튼 또는 현재 페이지 일괄 수집을 사용할 수 있습니다.</span>
    </div>
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
                                @endif
                                @if (!empty($p->link))
                                    <button type="button" class="btn btn-secondary btn-sm rf-seller-captcha-one"
                                            data-url="{{ $p->link }}"
                                            data-title="{{ $p->title }}"
                                            data-store="{{ $storeId }}">
                                        {{ $hasInfo ? '재수집' : '수집' }}
                                    </button>
                                @elseif (!$hasInfo)
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

@if (!empty($recentSellerInfos) && $recentSellerInfos->isNotEmpty())
    <div class="card p-4 mt-4">
        <div class="flex items-center justify-between gap-3 mb-3">
            <div>
                <h2 class="font-semibold text-ink" style="font-size:var(--fs-md);">최근 수집한 판매자정보</h2>
                <p class="text-muted" style="font-size:var(--fs-xs);margin-top:2px;">캡차 통과 후 확보한 사업자 정보입니다.</p>
            </div>
        </div>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:var(--fs-sm);">
                <thead>
                    <tr class="text-muted-soft" style="text-align:left;border-bottom:1px solid var(--color-hairline);">
                        <th style="padding:8px 6px;width:110px;">수집일</th>
                        <th style="padding:8px 6px;">상호명 · 대표자</th>
                        <th style="padding:8px 6px;width:120px;">사업자등록번호</th>
                        <th style="padding:8px 6px;width:130px;">고객센터</th>
                        <th style="padding:8px 6px;width:150px;">통신판매업번호</th>
                        <th style="padding:8px 6px;">e-mail</th>
                        <th style="padding:8px 6px;width:120px;">스토어ID</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentSellerInfos as $si)
                        <tr style="border-bottom:1px solid var(--color-hairline-soft);">
                            <td style="padding:8px 6px;" class="font-mono text-muted-soft">
                                {{ $si->captured_at ? $si->captured_at->format('m-d H:i') : $si->created_at?->format('m-d H:i') }}
                            </td>
                            <td style="padding:8px 6px;">
                                <span class="text-ink font-semibold">{{ $si->biz_name ?: '—' }}</span>
                                @if ($si->representative)
                                    <span class="text-muted-soft" style="font-size:var(--fs-xs);">· {{ $si->representative }}</span>
                                @endif
                                @if ($si->address)
                                    <div class="text-muted-soft" style="font-size:var(--fs-xs);margin-top:2px;">{{ $si->address }}</div>
                                @endif
                            </td>
                            <td style="padding:8px 6px;" class="font-mono text-muted">{{ $si->biz_reg_no ?: '—' }}</td>
                            <td style="padding:8px 6px;" class="font-mono text-muted">{{ $si->customer_phone ?: '—' }}</td>
                            <td style="padding:8px 6px;" class="font-mono text-muted">{{ $si->mail_order_no ?: '—' }}</td>
                            <td style="padding:8px 6px;" class="text-muted">
                                {{ $si->email ?: '—' }}
                                @if ($si->seller_info_url)
                                    <a href="{{ $si->seller_info_url }}" target="_blank" rel="noopener"
                                       class="text-muted-soft" style="margin-left:6px;text-decoration:none;font-size:var(--fs-xs);">원문</a>
                                @endif
                            </td>
                            <td style="padding:8px 6px;" class="font-mono text-muted">{{ $si->store_id ?: '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@if ($items->hasPages())
    <div class="mt-4">{{ $items->links() }}</div>
@endif

<script>
    (function () {
        var allBtn = document.getElementById('rf-seller-captcha-all');
        var stopBtn = document.getElementById('rf-seller-captcha-stop');
        var conc = document.getElementById('rf-seller-captcha-conc');
        var msg = document.getElementById('rf-seller-captcha-msg');
        var pollTimer = null;

        function hasExtension() {
            return document.documentElement.getAttribute('data-rf-ext') === '1';
        }

        function callExt(type, payload, cb) {
            var onRes = function (e) {
                var m = e.data;
                if (!m || m.source !== 'rankfree-ext' || m.type !== type + 'Result') return;
                window.removeEventListener('message', onRes);
                cb(m);
            };
            window.addEventListener('message', onRes);
            window.postMessage(Object.assign({ source: 'rankfree-admin', type: type }, payload || {}), '*');
        }

        function productFromButton(btn) {
            return {
                url: btn.getAttribute('data-url') || '',
                title: btn.getAttribute('data-title') || '',
                storeId: btn.getAttribute('data-store') || ''
            };
        }

        function currentProducts() {
            return Array.prototype.slice.call(document.querySelectorAll('.rf-seller-captcha-one'))
                .map(productFromButton)
                .filter(function (p) { return p.url; });
        }

        function setRunning(on) {
            if (allBtn) allBtn.disabled = on;
            if (conc) conc.disabled = on;
            if (stopBtn) stopBtn.hidden = !on;
            document.querySelectorAll('.rf-seller-captcha-one').forEach(function (b) { b.disabled = on; });
        }

        function renderJob(job) {
            job = job || {};
            var running = !!job.running;
            var text = (running ? '수집 중 ' : '수집 종료 ') + (job.done || 0) + '/' + (job.total || 0) +
                ' · 저장 ' + (job.saved || 0) +
                ' · 실패 ' + (job.failed || 0);
            if (running && job.inFlight) text += ' · 처리 중 ' + job.inFlight;
            if (job.concurrency) text += ' · 동시 ' + job.concurrency;
            if (running && job.current) text += ' · 현재: ' + job.current;
            if (job.lastError) text += ' · 최근 오류: ' + job.lastError;
            msg.textContent = text;
        }

        function poll() {
            callExt('sellerCaptchaStatus', {}, function (res) {
                var job = res && res.job;
                renderJob(job);
                if (job && job.running) {
                    pollTimer = setTimeout(poll, 1000);
                } else {
                    setRunning(false);
                    pollTimer = null;
                }
            });
        }

        function selectedConcurrency() {
            return Math.max(1, Math.min(4, Number(conc && conc.value) || 3));
        }

        function start(products, options) {
            options = options || {};
            if (!hasExtension()) {
                msg.style.color = 'var(--color-error)';
                msg.textContent = '확장이 설치돼 있지 않습니다. RankFree 확장을 리로드하고 로그인해 주세요.';
                return;
            }
            if (!products.length) {
                msg.style.color = 'var(--color-error)';
                msg.textContent = '처리할 상품 URL이 없습니다.';
                return;
            }
            msg.style.color = '';
            msg.textContent = '판매자정보 수집 작업을 시작합니다...';
            setRunning(true);
            callExt('sellerCaptchaStart', {
                products: products,
                concurrency: options.concurrency || selectedConcurrency(),
                active: !!options.active,
                keepOpen: !!options.keepOpen
            }, function (res) {
                if (!res || !res.ok) {
                    setRunning(false);
                    msg.style.color = 'var(--color-error)';
                    msg.textContent = (res && res.message) || '판매자정보 수집 시작에 실패했습니다.';
                    return;
                }
                poll();
            });
        }

        if (allBtn) {
            allBtn.addEventListener('click', function () {
                start(currentProducts(), { concurrency: selectedConcurrency() });
            });
        }
        if (stopBtn) {
            stopBtn.addEventListener('click', function () {
                callExt('sellerCaptchaStop', {}, function () {
                    msg.textContent = '중단 요청을 보냈습니다. 현재 처리 중인 상품을 정리하고 멈춥니다.';
                    setRunning(true);
                    if (!pollTimer) poll();
                });
            });
        }
        document.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('.rf-seller-captcha-one');
            if (!btn) return;
            start([productFromButton(btn)], { active: true, keepOpen: true, concurrency: 1 });
        });

        (function recover(n) {
            if (hasExtension()) {
                callExt('sellerCaptchaStatus', {}, function (res) {
                    if (res && res.job) {
                        renderJob(res.job);
                        if (res.job.running) {
                            setRunning(true);
                            if (!pollTimer) poll();
                        }
                    }
                });
                return;
            }
            if (n > 0) setTimeout(function () { recover(n - 1); }, 300);
        })(20);
    })();

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
