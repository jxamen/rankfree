@extends('console.layout')
@section('page-title', '플레이스 개별 분석 · '.$analysis->name)
@section('crumb-parent', 'console.place-store')
@section('crumb-title', $analysis->name)
@section('active-menu', 'console.place-store')

@section('console-content')
@php
    $d = (array) $analysis->detail;
    $dims = (array) ($d['d'] ?? []);
    $kc = (array) ($d['kc'] ?? []);
    $seo = array_values(array_filter((array) ($d['seo'] ?? []), fn ($s) => ! empty($s['avail'])));
    $rep = array_values((array) ($d['rep_keywords'] ?? []));
    $reviewKw = (array) ($d['review_kw'] ?? []);
    $quality = (array) ($d['review_quality'] ?? []);
    $benchmark = (array) ($d['benchmark'] ?? []);
    $competitors = array_values((array) ($d['competitors'] ?? []));
    $fmt = fn ($v, $dec = 0) => $v === null ? '-' : number_format((float) $v, $dec);
    $score = fn ($v) => $v === null ? '-' : round((float) $v);
    $barw = fn ($v) => $v === null ? 0 : max(2, min(100, round((float) $v)));
    $rankText = $analysis->rank && $analysis->rank < 300 ? $analysis->rank.'위' : '300+';
    $radar = [
        $analysis->n3 === null ? 0 : (float) $analysis->n3,
        $analysis->n2 === null ? 0 : (float) $analysis->n2,
        $dims['d7'] ?? 0,
        $dims['d1'] ?? 0,
        $dims['d2'] ?? 0,
    ];
    $kvRows = [
        ['방문자 리뷰', $analysis->visitor_cnt, $benchmark['visitor_avg'] ?? null],
        ['블로그 리뷰', $analysis->blog_cnt, $benchmark['blog_avg'] ?? null],
        ['저장수', $analysis->save_cnt, $benchmark['save_avg'] ?? null],
    ];
    $dimLabels = [
        'd1' => '방문자 리뷰',
        'd2' => '블로그 리뷰',
        'd3' => '예약 리뷰',
        'd4' => '평점',
        'd5' => '저장수',
        'd6' => '사진수',
        'd7' => '정보 충실도',
        'd8' => '키워드 일치',
        'd9' => '최근 활동',
        'd10' => '리뷰 영향력',
    ];
@endphp

<style>
    .ps-score-row { display:flex; align-items:center; gap:12px; margin:9px 0; font-size:var(--fs-xs); }
    .ps-score-row .l { width:116px; flex:none; color:var(--color-muted); }
    .ps-score-row .t { flex:1; height:8px; background:var(--color-surface-strong); border-radius:99px; overflow:hidden; }
    .ps-score-row .t span { display:block; height:100%; border-radius:99px; background:var(--color-primary); }
    .ps-score-row .v { width:44px; text-align:right; color:var(--color-ink); font-weight:600; }
    .ps-chip { display:inline-flex; align-items:center; gap:5px; border:1px solid var(--color-hairline); border-radius:999px; padding:5px 10px; font-size:var(--fs-xs); color:var(--color-ink); background:var(--color-canvas); }
    .ps-chip b { color:var(--color-primary); font-weight:700; }
</style>

<div>
    <a href="{{ route('console.place-store') }}" class="text-muted hover:text-ink" style="font-size:var(--fs-xs);">← 개별 분석 목록</a>

    <div class="flex items-end justify-between flex-wrap gap-3 mt-2 mb-5">
        <div>
            <div class="font-display text-ink" style="font-size:var(--fs-xl);">{{ $analysis->name }}</div>
            <div class="text-muted-soft" style="font-size:var(--fs-xs);">
                {{ $analysis->keyword }} · {{ $d['category'] ?? ($analysis->cat ?: 'place') }} · {{ optional($analysis->updated_at)->format('Y.m.d H:i') }} 분석
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" class="btn btn-secondary btn-sm" id="ps-copy-share" data-url="{{ $analysis->shareUrl() }}">공유</button>
            <form method="POST" action="{{ route('console.place-store.store') }}" id="ps-refresh-form">
                @csrf
                <input type="hidden" name="place" value="{{ $d['place_url'] ?? $analysis->place_id }}">
                <input type="hidden" name="keyword" value="{{ $analysis->keyword }}">
                <button type="submit" class="btn btn-primary btn-sm">재분석</button>
            </form>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        @foreach ([['검색 순위', $rankText, null], ['N1 유사도', $score($analysis->n1), $analysis->n1], ['N2 관련성', $score($analysis->n2), $analysis->n2], ['N3 랭킹', $score($analysis->n3), $analysis->n3]] as [$label, $val, $sc])
            <div class="card p-4">
                <div class="text-muted" style="font-size:var(--fs-xs);">{{ $label }}</div>
                <div class="font-display text-ink mt-1" style="font-size:var(--fs-xl);">{{ $val }}</div>
                @if ($sc !== null)
                    <div class="mt-2" style="height:6px;background:var(--color-surface-strong);border-radius:99px;overflow:hidden;"><div style="height:100%;width:{{ $barw($sc) }}%;background:var(--color-primary);"></div></div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-5">
        <div class="card p-6">
            <div class="flex items-start justify-between gap-3 mb-3">
                <div>
                    <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">내 매장 진단</div>
                    <div class="text-muted-soft" style="font-size:var(--fs-xs);">순위·플레이스 지수·리뷰 신호를 100점 기준으로 표시합니다.</div>
                </div>
                <div class="text-right">
                    <div class="text-muted-soft" style="font-size:var(--fs-xs);">종합</div>
                    <div class="font-display text-primary" style="font-size:32px;line-height:1;">{{ $score($analysis->n3) }}</div>
                </div>
            </div>
            <canvas id="ps-radar" width="440" height="300" style="width:100%;max-width:520px;height:300px;display:block;margin:0 auto;"></canvas>
            <div class="grid grid-cols-3 gap-2 mt-3">
                @foreach ([['방문자 리뷰', $analysis->visitor_cnt], ['블로그 리뷰', $analysis->blog_cnt], ['저장수', $analysis->save_cnt]] as [$label, $value])
                    <div class="text-center rounded-md" style="border:1px solid var(--color-hairline);padding:10px 8px;">
                        <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $label }}</div>
                        <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">{{ $value === null ? '-' : number_format((int) $value) }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="card p-6">
            <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">상위 {{ $benchmark['top_count'] ?? 10 }}개 업체 평균 비교</div>
            <div class="text-muted-soft mb-5" style="font-size:var(--fs-xs);">현재 키워드 검색 결과의 상위 업체 평균과 내 매장을 비교합니다.</div>
            @foreach ($kvRows as [$label, $mine, $avg])
                @php $max = max(1, (float) ($mine ?? 0), (float) ($avg ?? 0)); @endphp
                <div class="mb-5">
                    <div class="text-ink mb-2" style="font-size:var(--fs-xs);font-weight:600;">{{ $label }}</div>
                    <div class="ps-score-row"><span class="l">내 매장</span><span class="t"><span style="width:{{ round(((float) ($mine ?? 0)) / $max * 100) }}%;background:var(--color-primary);"></span></span><span class="v">{{ $mine === null ? '-' : number_format((int) $mine) }}</span></div>
                    <div class="ps-score-row"><span class="l">상위 평균</span><span class="t"><span style="width:{{ round(((float) ($avg ?? 0)) / $max * 100) }}%;background:var(--color-accent);"></span></span><span class="v">{{ $avg === null ? '-' : number_format((float) $avg, $label === '평점' ? 1 : 0) }}</span></div>
                </div>
            @endforeach
            <p class="text-muted-soft" style="font-size:var(--fs-xs);">전체 검색 결과 수: {{ number_format((int) ($benchmark['total'] ?? 0)) }}개</p>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-5">
        <div class="card p-6">
            <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">세부 지수</div>
            @foreach ($dimLabels as $key => $label)
                @php $v = $dims[$key] ?? null; @endphp
                <div class="ps-score-row"><span class="l">{{ $label }}</span><span class="t"><span style="width:{{ $barw($v) }}%;"></span></span><span class="v">{{ $score($v) }}</span></div>
            @endforeach
        </div>

        <div class="card p-6">
            <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">키워드 일치 요소</div>
            @foreach ([['지역', 'L', 'region'], ['업종', 'B', 'bizterm'], ['대표 키워드', 'T', 'core'], ['상호명', 'M', null]] as [$label, $key, $sub])
                @php $v = array_key_exists($key, $kc) && $kc[$key] !== null ? ((float) $kc[$key]) * 100 : null; @endphp
                <div class="ps-score-row">
                    <span class="l">{{ $label }} @if ($sub && ! empty($kc[$sub]))<span class="text-muted-soft">{{ $kc[$sub] }}</span>@endif</span>
                    <span class="t"><span style="width:{{ $barw($v) }}%;"></span></span>
                    <span class="v">{{ $score($v) }}</span>
                </div>
            @endforeach
            @if ($rep)
                <div class="text-muted mt-5 mb-2" style="font-size:var(--fs-xs);font-weight:600;">대표 키워드</div>
                <div class="flex flex-wrap gap-2">
                    @foreach (array_slice($rep, 0, 30) as $tag)
                        <span class="ps-chip">{{ $tag }}</span>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    @if ($reviewKw || $quality)
        <div class="card p-6 mb-5">
            <div class="text-ink font-semibold mb-4" style="font-size:var(--fs-sm);">고객 반응 키워드</div>
            @foreach ([['voted', '방문자가 직접 고른 키워드'], ['menus', '리뷰 속 메뉴·토픽 키워드'], ['themes', '리뷰가 많이 언급하는 요소']] as [$key, $label])
                @php $items = array_values((array) ($reviewKw[$key] ?? [])); @endphp
                @if ($items)
                    <div class="mb-4">
                        <div class="text-muted mb-2" style="font-size:var(--fs-xs);font-weight:600;">{{ $label }}</div>
                        <div class="flex flex-wrap gap-2">
                            @foreach (array_slice($items, 0, 18) as $item)
                                <span class="ps-chip">{{ $item['l'] ?? '' }} @if(isset($item['c']))<b>{{ number_format((int) $item['c']) }}</b>@endif</span>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach

            @php $ctx = (array) ($quality['ctx'] ?? []); $maxCtx = max(1, (int) max($ctx ?: [1])); @endphp
            @if ($ctx)
                <div class="text-muted mt-5 mb-2" style="font-size:var(--fs-xs);font-weight:600;">최근 리뷰 문맥</div>
                @foreach (array_slice($ctx, 0, 8, true) as $label => $count)
                    <div class="ps-score-row"><span class="l">{{ $label }}</span><span class="t"><span style="width:{{ round(((int) $count) / $maxCtx * 100) }}%;background:var(--color-primary);"></span></span><span class="v">{{ number_format((int) $count) }}</span></div>
                @endforeach
            @endif
        </div>
    @endif

    @if ($seo)
        <div class="card p-6 mb-5">
            <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">정보 충실도 체크</div>
            @foreach ($seo as $item)
                @php $v = isset($item['grade']) ? ((float) $item['grade']) * 100 : null; @endphp
                <div class="ps-score-row">
                    <span class="l">{{ $item['label'] ?? '' }}</span>
                    <span class="t"><span style="width:{{ $barw($v) }}%;"></span></span>
                    <span class="v">{{ $score($v) }}</span>
                </div>
            @endforeach
        </div>
    @endif

    @if ($competitors)
        <div class="card overflow-x-auto">
            <div class="px-5 pt-5 text-ink font-semibold" style="font-size:var(--fs-sm);">경쟁사 순위표</div>
            <table class="w-full mt-3" style="min-width:760px;">
                <thead>
                    <tr class="text-muted" style="font-size:var(--fs-xs);border-top:1px solid var(--color-hairline-soft);border-bottom:1px solid var(--color-hairline-soft);">
                        <th class="text-right px-4 py-3 font-semibold" style="width:64px;">순위</th>
                        <th class="text-left px-3 py-3 font-semibold">매장</th>
                        <th class="text-right px-3 py-3 font-semibold">방문자</th>
                        <th class="text-right px-3 py-3 font-semibold">블로그</th>
                        <th class="text-right px-4 py-3 font-semibold">저장수</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($competitors as $row)
                        @php $isMine = (string) ($row['place_id'] ?? '') === (string) $analysis->place_id; @endphp
                        <tr style="border-top:1px solid var(--color-hairline-soft);{{ $isMine ? 'background:color-mix(in srgb,var(--color-primary) 7%,var(--color-canvas));' : '' }}">
                            <td class="px-4 py-3 text-right text-muted" style="font-size:var(--fs-xs);">{{ $row['rnk'] ?? '-' }}</td>
                            <td class="px-3 py-3 text-ink" style="font-size:var(--fs-xs);font-weight:{{ $isMine ? 700 : 500 }};">{{ $isMine ? '내 매장 · ' : '' }}{{ $row['name'] ?? '' }}</td>
                            <td class="px-3 py-3 text-right text-muted" style="font-size:var(--fs-xs);">{{ isset($row['visitor_cnt']) ? number_format((int) $row['visitor_cnt']) : '-' }}</td>
                            <td class="px-3 py-3 text-right text-muted" style="font-size:var(--fs-xs);">{{ isset($row['blog_cnt']) ? number_format((int) $row['blog_cnt']) : '-' }}</td>
                            <td class="px-4 py-3 text-right text-muted" style="font-size:var(--fs-xs);">{{ isset($row['save_cnt']) ? number_format((int) $row['save_cnt']) : '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <p class="text-muted-soft mt-4" style="font-size:var(--fs-xs);line-height:1.7;">N1·N2·N3와 D1~D10은 관측 신호 기반 자체 추정치이며 네이버 공식 점수가 아닙니다.</p>
</div>

<script>
(function () {
    const canvas = document.getElementById('ps-radar');
    if (canvas) {
        const labels = ['노출 순위', '플레이스 지수', '보조지수', '방문자 리뷰', '블로그 리뷰'];
        const values = @json(array_values($radar));
        const dpr = window.devicePixelRatio || 1;
        const rect = canvas.getBoundingClientRect();
        canvas.width = Math.max(1, Math.floor(rect.width * dpr));
        canvas.height = Math.max(1, Math.floor(rect.height * dpr));
        const ctx = canvas.getContext('2d');
        ctx.scale(dpr, dpr);
        const w = rect.width;
        const h = rect.height;
        const cx = w / 2;
        const cy = h / 2 + 8;
        const r = Math.min(w, h) * 0.32;
        ctx.lineWidth = 1;
        ctx.font = '12px sans-serif';
        for (let ring = 1; ring <= 4; ring++) {
            ctx.beginPath();
            for (let i = 0; i < labels.length; i++) {
                const a = -Math.PI / 2 + i * Math.PI * 2 / labels.length;
                const rr = r * ring / 4;
                const x = cx + Math.cos(a) * rr;
                const y = cy + Math.sin(a) * rr;
                if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
            }
            ctx.closePath();
            ctx.strokeStyle = 'rgba(148, 163, 184, .28)';
            ctx.stroke();
        }
        labels.forEach(function (label, i) {
            const a = -Math.PI / 2 + i * Math.PI * 2 / labels.length;
            ctx.beginPath();
            ctx.moveTo(cx, cy);
            ctx.lineTo(cx + Math.cos(a) * r, cy + Math.sin(a) * r);
            ctx.strokeStyle = 'rgba(148, 163, 184, .26)';
            ctx.stroke();
            const lx = cx + Math.cos(a) * (r + 34);
            const ly = cy + Math.sin(a) * (r + 24);
            ctx.fillStyle = '#64748b';
            ctx.textAlign = lx < cx - 10 ? 'right' : (lx > cx + 10 ? 'left' : 'center');
            ctx.fillText(label, lx, ly);
        });
        ctx.beginPath();
        values.forEach(function (raw, i) {
            const v = Math.max(0, Math.min(100, Number(raw) || 0));
            const a = -Math.PI / 2 + i * Math.PI * 2 / labels.length;
            const x = cx + Math.cos(a) * r * v / 100;
            const y = cy + Math.sin(a) * r * v / 100;
            if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        });
        ctx.closePath();
        ctx.fillStyle = 'rgba(22, 163, 74, .18)';
        ctx.strokeStyle = 'rgb(22, 163, 74)';
        ctx.lineWidth = 2;
        ctx.fill();
        ctx.stroke();
    }

    const refresh = document.getElementById('ps-refresh-form');
    if (refresh) {
        refresh.addEventListener('submit', function () {
            Swal.fire({
                title: '재분석 중',
                html: '<span style="font-size:var(--fs-xs);color:var(--color-muted);">최신 데이터로 다시 수집합니다.</span>',
                allowOutsideClick: false,
                showConfirmButton: false,
                didOpen: function () { Swal.showLoading(); }
            });
        });
    }

    const copy = document.getElementById('ps-copy-share');
    if (copy) {
        copy.addEventListener('click', function () {
            const url = new URL(copy.dataset.url, location.origin).href;
            function done() { Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: '공유 링크를 복사했습니다.', showConfirmButton: false, timer: 1500 }); }
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
    }
})();
</script>
@endsection
