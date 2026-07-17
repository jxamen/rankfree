@extends('admin.layout')
@section('page-title', $keyword.' — 키워드 상세')
@section('crumb-parent', 'admin.keyword-browse')
@section('crumb-title', $keyword)

@php
    $catLabel = ['restaurant' => '맛집·음식점', 'hospital' => '병원·의원', 'hairshop' => '헤어샵', 'nailshop' => '네일·뷰티', 'accommodation' => '숙박·여행', 'place' => '플레이스'];
    $num = fn ($v) => $v === null ? '—' : number_format((int) $v);
@endphp

@section('admin-content')
<x-console.page-head :title="$keyword" desc="이 키워드로 네이버에 실제 노출되는 업체입니다 — 순위·리뷰·저장수와 place+·새로오픈·톡톡 여부." />

<div class="flex items-center gap-2 flex-wrap mb-4">
    <a href="{{ route('admin.keyword-browse', ['type' => $type, 'q' => $keyword]) }}" class="btn btn-secondary btn-sm">← 키워드 탐색</a>
    <span class="badge border border-hairline">{{ $type === 'place' ? '플레이스' : '쇼핑' }}</span>
    @if ($type === 'place')
        <span class="badge border border-hairline">{{ $catLabel[$cat] ?? $cat }}</span>
    @endif
    @if ($candidate?->monthly_total !== null)
        <span class="text-muted" style="font-size:var(--fs-xs);">월 검색량 <b class="font-mono text-ink">{{ number_format($candidate->monthly_total) }}</b></span>
    @endif
    <a href="https://map.naver.com/p/search/{{ urlencode($keyword) }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">네이버에서 보기</a>
    <a href="{{ route('admin.keyword-browse.detail', ['keyword' => $keyword, 'refresh' => 1]) }}" class="btn btn-ghost btn-sm ml-auto" title="캐시를 무시하고 다시 수집">다시 수집</a>
</div>

@if ($type !== 'place')
    {{-- 쇼핑 — 서버에서 search.shopping 이 418 로 막혀 확장 수집이 필요하다 --}}
    <div class="card p-6 text-center">
        <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">쇼핑 상품 수집은 확장 프로그램이 필요합니다</div>
        <p class="text-muted mt-2" style="font-size:var(--fs-xs);line-height:1.7;">
            네이버 쇼핑 검색 API는 서버 요청을 차단(418)하기 때문에, 상품 목록(상위 80개)은 브라우저에서 확장이 수집해야 합니다.<br>
            아래 버튼으로 네이버 쇼핑을 열면 확장이 시장 분석으로 수집합니다.
        </p>
        <a href="https://search.shopping.naver.com/search/all?query={{ urlencode($keyword) }}" target="_blank" rel="noopener" class="btn btn-primary btn-sm mt-4">네이버 쇼핑에서 열기</a>
    </div>
@elseif ($serp['blocked'])
    <div class="card p-6 text-center">
        <div class="text-error font-semibold" style="font-size:var(--fs-sm);">수집이 차단되었습니다 (405/429)</div>
        <p class="text-muted mt-2" style="font-size:var(--fs-xs);line-height:1.7;">
            pcmap 순위 조회에는 nCaptcha 토큰이 필요합니다. 토큰이 만료되었을 수 있습니다 — 토큰 발급 후 다시 시도하세요.
        </p>
    </div>
@else
    {{-- 집계 --}}
    @php
        $items = collect($serp['items']);
        $cntPlus = $items->where('place_plus', true)->count();
        $cntNew = $items->where('new_opening', true)->count();
        $cntTalk = $items->filter(fn ($i) => ($i['talktalk_id'] ?? '') !== '')->count();
    @endphp
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-4">
        @foreach ([
            ['수집 업체', number_format($items->count()).'개'],
            ['전체 노출', number_format((int) $serp['total']).'개'],
            ['place+', number_format($cntPlus).'개'],
            ['새로오픈', number_format($cntNew).'개'],
            ['톡톡 연결', number_format($cntTalk).'개'],
        ] as [$l, $v])
            <div class="card p-3 text-center">
                <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $l }}</div>
                <div class="font-mono text-ink font-semibold" style="font-size:var(--fs-sm);">{{ $v }}</div>
            </div>
        @endforeach
    </div>

    <div class="card p-5">
        <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
            <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">노출 업체 <span class="font-mono text-muted">{{ number_format($items->count()) }}</span></div>
            <span class="text-muted-soft" style="font-size:var(--fs-xs);">
                수집 {{ $serp['cached_at'] ? \Carbon\Carbon::parse($serp['cached_at'])->diffForHumans() : '방금' }} · 6시간 캐시 · 서울 좌표 기준
            </span>
        </div>
        <div style="overflow-x:auto;">
            <table class="w-full" style="font-size:var(--fs-xs);border-collapse:collapse;">
                <thead>
                    <tr class="text-muted-soft" style="text-align:left;border-bottom:1px solid var(--color-hairline);">
                        <th style="padding:8px 6px;width:44px;text-align:right;">순위</th>
                        <th style="padding:8px 6px;">업체명</th>
                        <th style="padding:8px 6px;">배지</th>
                        <th style="padding:8px 6px;">톡톡</th>
                        <th style="padding:8px 6px;text-align:right;">방문리뷰</th>
                        <th style="padding:8px 6px;text-align:right;">블로그</th>
                        <th style="padding:8px 6px;text-align:right;">예약</th>
                        <th style="padding:8px 6px;text-align:right;">저장</th>
                        <th style="padding:8px 6px;text-align:right;">사진</th>
                        <th style="padding:8px 6px;text-align:right;">평점</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $i)
                        <tr style="border-bottom:1px solid var(--color-hairline-soft);">
                            <td style="padding:7px 6px;text-align:right;" class="font-mono text-muted">{{ $i['rnk'] }}</td>
                            <td style="padding:7px 6px;">
                                <a href="https://m.place.naver.com/place/{{ $i['place_id'] }}" target="_blank" rel="noopener"
                                   class="text-ink font-semibold" style="text-decoration:none;">{{ $i['name'] }}</a>
                            </td>
                            <td style="padding:7px 6px;">
                                @if (!empty($i['place_plus']))<span class="badge" style="font-size:var(--fs-xs);padding:1px 6px;" title="place+">place+</span>@endif
                                @if (!empty($i['new_opening']))<span class="badge" style="font-size:var(--fs-xs);padding:1px 6px;color:var(--color-error);" title="새로오픈">새로오픈</span>@endif
                                @if (empty($i['place_plus']) && empty($i['new_opening']))<span class="text-muted-soft">—</span>@endif
                            </td>
                            <td style="padding:7px 6px;">
                                @if (($i['talktalk_id'] ?? '') !== '')
                                    <a href="{{ $i['talktalk_url'] }}" target="_blank" rel="noopener" class="font-mono" style="color:var(--color-primary);text-decoration:none;">{{ $i['talktalk_id'] }}</a>
                                @else
                                    <span class="text-muted-soft">—</span>
                                @endif
                            </td>
                            <td style="padding:7px 6px;text-align:right;" class="font-mono">{{ $num($i['visitor_cnt'] ?? null) }}</td>
                            <td style="padding:7px 6px;text-align:right;" class="font-mono">{{ $num($i['blog_cnt'] ?? null) }}</td>
                            <td style="padding:7px 6px;text-align:right;" class="font-mono">{{ $num($i['booking_cnt'] ?? null) }}</td>
                            <td style="padding:7px 6px;text-align:right;" class="font-mono">{{ $num($i['save_cnt'] ?? null) }}</td>
                            <td style="padding:7px 6px;text-align:right;" class="font-mono">{{ $num($i['img_cnt'] ?? null) }}</td>
                            <td style="padding:7px 6px;text-align:right;" class="font-mono">{{ $i['review_score'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-muted-soft text-center" style="padding:40px;">노출 업체가 없습니다.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
