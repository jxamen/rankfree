@extends('admin.layout')
@section('page-title', '키워드 콘텐츠 허브')

@php
    $srcLabel = ['seed' => '시드', 'related' => '연관', 'autocomplete' => '자동완성', 'user' => '사용자', 'gsc' => '검색유입', 'datalab' => '데이터랩', 'combo' => '지역조합'];
    $stLabel = ['pending' => '대기', 'approved' => '승인', 'rejected' => '거부', 'published' => '발행됨'];
    $rtLabel = ['hotplace' => '핫플', 'district' => '구', 'city' => '시', 'dong' => '동', 'travel' => '여행지'];
@endphp

@section('admin-content')
<x-console.page-head title="키워드 콘텐츠 허브" desc="카테고리 시드에서 키워드 후보를 수집하고, 승인한 후보를 분석 문서(/keyword/슬러그)로 발행합니다 — 수집→승인→발행→갱신 (설계 .claude/22)" />

{{-- 플래시(status)는 admin.layout 이 전역 표시 — 여기서는 검증 오류만 --}}
@if ($errors->any())
    <div class="card-soft px-4 py-3 mb-4" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
@endif

{{-- 현황 + 실행 --}}
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-5">
    <div class="card p-5">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">후보 현황</div>
        <div class="flex flex-wrap gap-2">
            @foreach ($stLabel as $k => $label)
                <a href="{{ route('admin.keyword-hub', ['status' => $k]) }}" class="badge border border-hairline" style="font-size:var(--fs-xs);{{ $status === $k ? 'background:var(--color-ink);color:var(--color-canvas);' : '' }}">
                    {{ $label }} <b class="font-mono">{{ number_format($counts[$k] ?? 0) }}</b>
                </a>
            @endforeach
        </div>
        {{-- 출처별 후보 수(현재 상태 기준) — 시딩(지역조합) 결과를 바로 확인. 클릭 시 해당 출처로 필터 --}}
        @if (!empty($sourceCounts) && count($sourceCounts))
            <div class="flex flex-wrap gap-1.5 mt-3 pt-3" style="border-top:1px solid var(--color-hairline-soft);">
                <span class="text-muted-soft" style="font-size:var(--fs-xs);">출처</span>
                @foreach ($srcLabel as $k => $label)
                    @isset($sourceCounts[$k])
                        <a href="{{ route('admin.keyword-hub', ['status' => $status, 'source' => $k, 'category' => $catId ?: null]) }}"
                           class="badge border border-hairline" style="font-size:var(--fs-xs);{{ ($source ?? '') === $k ? 'background:var(--color-ink);color:var(--color-canvas);' : '' }}">
                            {{ $label }} <b class="font-mono">{{ number_format($sourceCounts[$k]) }}</b>
                        </a>
                    @endisset
                @endforeach
            </div>
        @endif
        <div class="text-muted mt-3" style="font-size:var(--fs-xs);">발행 문서 <b class="font-mono text-ink">{{ number_format($hubDocCount) }}</b>개 — 사이트맵 keyword 섹션에 자동 포함</div>
    </div>

    <div class="card p-5">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">지금 수집</div>
        <form method="POST" action="{{ route('admin.keyword-hub.collect') }}" class="flex items-end gap-2">
            @csrf
            <div class="flex-1">
                <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">카테고리(비우면 로테이션 순서)</label>
                <select name="category_id" class="input">
                    <option value="">— 자동(오래된 순) —</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}">{{ $c->parent ? $c->parent->name.' › ' : '' }}{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-secondary btn-sm" style="height:40px;">수집 실행</button>
        </form>
        <div class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">시드 → 검색광고 연관 + 자동완성 → 후보(대기). 월 {{ number_format((int) config('rankfree.hub.min_volume')) }}회 미만은 자동 제외.</div>
    </div>

    <div class="card p-5">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">지금 발행</div>
        <form method="POST" action="{{ route('admin.keyword-hub.publish') }}" class="flex items-end gap-2">
            @csrf
            <div style="width:110px;">
                <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">발행 건수(≤10)</label>
                <input type="number" name="limit" class="input" min="1" max="10" value="3">
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="height:40px;">발행 실행</button>
        </form>
        <div class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">승인 후보를 검색량 큰 순으로 분석·발행합니다(검색량 없으면 자동 보류). 대량 발행은 hub:publish 크론으로.</div>
    </div>
</div>

{{-- 카테고리 관리 --}}
<div class="card p-5 mb-5">
    <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">카테고리 · 시드 키워드</div>

    <form method="POST" action="{{ route('admin.keyword-hub.categories.store') }}" class="grid grid-cols-1 sm:grid-cols-[110px_180px_1fr_auto] gap-3 items-end mb-4">
        @csrf
        <div>
            <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">유형</label>
            <select name="type" class="input">
                <option value="shopping">쇼핑</option>
                <option value="place">플레이스</option>
            </select>
        </div>
        <div>
            <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">상위 카테고리(선택)</label>
            <select name="parent_id" class="input">
                <option value="">— 대분류 —</option>
                @foreach ($categories->whereNull('parent_id') as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">이름 · 시드 키워드(콤마/줄바꿈)</label>
            <div class="grid grid-cols-[180px_1fr] gap-2">
                <input name="name" class="input" maxlength="80" placeholder="예: 캠핑용품" required value="{{ old('name') }}">
                <input name="seed_keywords" class="input" maxlength="3000" placeholder="예: 캠핑의자, 캠핑테이블" value="{{ old('seed_keywords') }}">
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm" style="height:40px;">＋ 추가</button>
    </form>

    <div class="flex flex-col gap-2">
        @forelse ($categories as $cat)
            <div class="card-soft p-3" style="{{ $cat->is_active ? '' : 'opacity:.55;' }}">
                <form method="POST" action="{{ route('admin.keyword-hub.categories.update', $cat) }}" class="grid grid-cols-1 sm:grid-cols-[90px_180px_1fr_70px_auto] gap-2 items-center">
                    @csrf @method('PUT')
                    <div class="flex items-center gap-1">
                        <span class="badge" style="font-size:var(--fs-xs);">{{ $cat->type === 'place' ? '플레이스' : '쇼핑' }}</span>
                    </div>
                    <input name="name" class="input" maxlength="80" value="{{ $cat->name }}" required title="{{ $cat->parent ? '상위: '.$cat->parent->name : '대분류' }}">
                    <input name="seed_keywords" class="input" maxlength="3000" value="{{ implode(', ', $cat->seedList()) }}" placeholder="시드 키워드(콤마 구분)" style="font-size:var(--fs-xs);">
                    <input name="sort" type="number" class="input" min="0" max="9999" value="{{ $cat->sort }}" title="정렬">
                    <div class="flex items-center gap-1.5 flex-wrap">
                        <button type="submit" class="btn btn-secondary btn-sm">저장</button>
                        <span class="badge" style="font-size:var(--fs-xs);" title="대기/승인/발행">
                            <span class="font-mono">{{ $cat->pending_count }}</span>/<span class="font-mono">{{ $cat->approved_count }}</span>/<span class="font-mono">{{ $cat->published_count }}</span>
                        </span>
                    </div>
                    <div class="sm:col-span-5 flex items-center gap-2">
                        <input name="description" class="input" maxlength="300" placeholder="설명(선택 — Phase 2 허브 페이지 소개문)" value="{{ $cat->description }}" style="font-size:var(--fs-xs);flex:1;">
                    </div>
                </form>
                <div class="flex items-center gap-2 mt-2 pt-2" style="border-top:1px solid var(--color-hairline-soft);">
                    <form method="POST" action="{{ route('admin.keyword-hub.categories.toggle', $cat) }}">
                        @csrf
                        <button type="submit" class="badge" style="font-size:var(--fs-xs);padding:2px 10px;cursor:pointer;{{ $cat->is_active ? 'background:color-mix(in srgb,var(--color-success) 14%,var(--color-canvas));color:var(--color-success);' : '' }}">{{ $cat->is_active ? 'ON · 수집대상' : 'OFF · 제외' }}</button>
                    </form>
                    <span class="text-muted-soft font-mono" style="font-size:var(--fs-xs);">/keywords/{{ $cat->slug }}</span>
                    <span class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $cat->collected_at ? '마지막 수집 '.$cat->collected_at->format('m-d H:i') : '수집 전' }}</span>
                    <form method="POST" action="{{ route('admin.keyword-hub.categories.destroy', $cat) }}" class="ml-auto" data-confirm="'{{ $cat->name }}' 카테고리를 삭제할까요?" data-confirm-text="후보는 함께 삭제되고, 이미 발행된 문서는 유지됩니다.">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-muted-soft hover:text-error" style="font-size:var(--fs-xs);background:none;border:0;cursor:pointer;">삭제</button>
                    </form>
                </div>
            </div>
        @empty
            <div class="text-muted-soft text-center" style="padding:24px;font-size:var(--fs-xs);">카테고리가 없습니다. 위에서 추가하고 시드 키워드를 넣은 뒤 '수집 실행'을 누르세요.</div>
        @endforelse
    </div>
</div>

{{-- 후보 승인 큐 --}}
<div class="card p-5 mb-5">
    <div class="flex items-center justify-between flex-wrap gap-2 mb-3">
        <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">후보 큐 — {{ $stLabel[$status] }} <span class="font-mono text-muted">{{ number_format($candidates->total()) }}</span></div>
        <form method="GET" action="{{ route('admin.keyword-hub') }}" class="flex items-center gap-2">
            <input type="hidden" name="status" value="{{ $status }}">
            <select name="source" class="input" style="height:36px;" onchange="this.form.submit()" title="출처 필터">
                <option value="">전체 출처</option>
                @foreach ($srcLabel as $k => $label)
                    <option value="{{ $k }}" @selected(($source ?? '') === $k)>{{ $label }}@isset($sourceCounts[$k]) ({{ number_format($sourceCounts[$k]) }})@endisset</option>
                @endforeach
            </select>
            <select name="category" class="input" style="height:36px;" onchange="this.form.submit()">
                <option value="">전체 카테고리</option>
                @foreach ($categories as $c)
                    <option value="{{ $c->id }}" @selected($catId === $c->id)>{{ $c->parent ? $c->parent->name.' › ' : '' }}{{ $c->name }}</option>
                @endforeach
            </select>
            <input type="search" name="q" class="input" style="height:36px;width:160px;" placeholder="키워드 검색" value="{{ $q ?? '' }}">
            <button type="submit" class="btn btn-secondary btn-sm" style="height:36px;">검색</button>
        </form>
    </div>

    <form method="POST" action="{{ route('admin.keyword-hub.candidates.bulk') }}" id="kh-bulk">
        @csrf
        <div style="overflow-x:auto;">
            <table class="w-full" style="font-size:var(--fs-xs);border-collapse:collapse;">
                <thead>
                    <tr class="text-muted-soft" style="text-align:left;border-bottom:1px solid var(--color-hairline);">
                        <th style="padding:8px 6px;width:34px;"><input type="checkbox" id="kh-all" title="전체 선택"></th>
                        <th style="padding:8px 6px;">키워드</th>
                        <th style="padding:8px 6px;">카테고리</th>
                        <th style="padding:8px 6px;">지역</th>
                        <th style="padding:8px 6px;">출처</th>
                        <th style="padding:8px 6px;text-align:right;">월 검색량</th>
                        <th style="padding:8px 6px;">경쟁</th>
                        <th style="padding:8px 6px;">비고</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($candidates as $c)
                        <tr style="border-bottom:1px solid var(--color-hairline-soft);">
                            <td style="padding:7px 6px;"><input type="checkbox" name="ids[]" value="{{ $c->id }}" class="kh-ck"></td>
                            <td style="padding:7px 6px;" class="text-ink font-semibold">{{ $c->keyword }}</td>
                            <td style="padding:7px 6px;" class="text-muted">{{ $c->category?->name ?? '—' }}</td>
                            <td style="padding:7px 6px;" class="text-muted">{{ $c->region ? $c->region.($c->region_type ? ' · '.($rtLabel[$c->region_type] ?? $c->region_type) : '') : '—' }}</td>
                            <td style="padding:7px 6px;"><span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">{{ $srcLabel[$c->source] ?? $c->source }}</span></td>
                            <td style="padding:7px 6px;text-align:right;" class="font-mono">{{ $c->monthly_total === null ? '미상' : number_format($c->monthly_total) }}</td>
                            <td style="padding:7px 6px;" class="text-muted">{{ $c->comp_idx ?? '—' }}</td>
                            <td style="padding:7px 6px;" class="text-muted-soft">{{ $c->note }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-muted-soft text-center" style="padding:28px;">'{{ $stLabel[$status] }}' 상태의 후보가 없습니다.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($candidates->count())
            <div class="flex items-center gap-2 mt-3 flex-wrap">
                <button type="submit" name="action" value="approve" class="btn btn-primary btn-sm">선택 승인</button>
                <button type="submit" name="action" value="reject" class="btn btn-secondary btn-sm">선택 거부</button>
                <button type="submit" name="action" value="pending" class="btn btn-secondary btn-sm">대기로</button>
                <button type="submit" name="action" value="delete" class="btn btn-ghost btn-sm" data-confirm="선택한 후보를 삭제할까요?">선택 삭제</button>
                <span class="text-muted-soft" style="font-size:var(--fs-xs);">승인한 후보는 '발행 실행' 또는 hub:publish 크론이 문서로 발행합니다.</span>
            </div>
        @endif
    </form>

    {{-- 필터 전체 일괄 처리 — 페이지 선택이 아니라 현재 필터(상태·카테고리·출처·검색어) 전체에 적용(대량 시딩 운영용) --}}
    @if ($candidates->total() > 0)
        <form method="POST" action="{{ route('admin.keyword-hub.candidates.bulk-all') }}" class="flex items-center gap-2 mt-3 pt-3 flex-wrap" style="border-top:1px solid var(--color-hairline-soft);">
            @csrf
            <input type="hidden" name="status" value="{{ $status }}">
            <input type="hidden" name="category" value="{{ $catId ?: '' }}">
            <input type="hidden" name="source" value="{{ $source ?? '' }}">
            <input type="hidden" name="q" value="{{ $q ?? '' }}">
            <span class="text-muted-soft" style="font-size:var(--fs-xs);">필터 전체 <b class="font-mono">{{ number_format($candidates->total()) }}</b>건 일괄:</span>
            <button type="submit" name="action" value="approve" class="btn btn-secondary btn-sm" data-confirm="현재 필터의 {{ number_format($candidates->total()) }}건을 모두 승인할까요?" data-confirm-text="승인된 후보는 hub:publish 크론이 검색량 큰 순으로 발행합니다.">전체 승인</button>
            <button type="submit" name="action" value="reject" class="btn btn-secondary btn-sm" data-confirm="현재 필터의 {{ number_format($candidates->total()) }}건을 모두 거부할까요?">전체 거부</button>
            <button type="submit" name="action" value="delete" class="btn btn-ghost btn-sm" data-confirm="현재 필터의 {{ number_format($candidates->total()) }}건을 모두 삭제할까요?" data-confirm-text="삭제는 되돌릴 수 없습니다.">전체 삭제</button>
        </form>
    @endif

    <div class="mt-3">{{ $candidates->links() }}</div>
</div>

{{-- 최근 발행 문서 --}}
<div class="card p-5">
    <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">최근 발행 문서</div>
    @forelse ($hubDocs as $d)
        <div class="flex items-center gap-2 py-1.5" style="border-bottom:1px solid var(--color-hairline-soft);font-size:var(--fs-xs);">
            <a href="{{ $d->shareUrl() }}" target="_blank" class="text-ink font-semibold" style="text-decoration:none;">{{ $d->keyword }}</a>
            <span class="font-mono text-muted">월 {{ number_format((int) $d->monthly_total) }}회</span>
            <span class="text-muted-soft ml-auto">{{ $d->refreshed_at?->format('m-d H:i') ?? $d->created_at->format('m-d H:i') }}</span>
        </div>
    @empty
        <div class="text-muted-soft" style="font-size:var(--fs-xs);">아직 발행된 허브 문서가 없습니다.</div>
    @endforelse
</div>

<script>
    document.getElementById('kh-all')?.addEventListener('change', function () {
        document.querySelectorAll('.kh-ck').forEach(function (el) { el.checked = this.checked; }, this);
    });
</script>
@endsection
