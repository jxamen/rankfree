@extends('admin.layout')
@section('page-title', '카테고리별 발행 문서')
@section('crumb-parent', 'admin.keyword-hub')

@php
    $typeLabel = ['place' => '플레이스 키워드 분석', 'shopping' => '쇼핑 시장 분석'];
    $isShopping = $type === 'shopping';
    $categoryPath = $category
        ? collect([$category->parent?->parent?->name, $category->parent?->name, $category->name])->filter()->implode(' › ')
        : ($isShopping ? '쇼핑 전체' : '플레이스 전체');
    $listRoute = $category
        ? route('admin.keyword-hub.published', ['type' => $type, 'category' => $category->id])
        : route('admin.keyword-hub.published-all', ['type' => $type]);
@endphp

@section('admin-content')
<x-console.page-head title="{{ $categoryPath }} 발행 문서" desc="{{ $typeLabel[$type] ?? $type }}로 발행된 키워드 목록입니다. 키워드를 누르면 실제 분석 문서가 열립니다." />

<div class="card p-5 mb-5">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div class="flex items-center gap-2 flex-wrap">
            <span class="badge border border-hairline" style="font-size:var(--fs-xs);">{{ $typeLabel[$type] ?? $type }}</span>
            <span class="text-muted-soft" style="font-size:var(--fs-xs);">총 <b class="font-mono text-ink">{{ number_format($docs->total()) }}</b>개</span>
        </div>
        <form method="GET" action="{{ $listRoute }}" class="flex items-center gap-2">
            <input type="search" name="q" class="input" style="height:36px;width:220px;" placeholder="키워드 검색" value="{{ $q }}">
            <button type="submit" class="btn btn-secondary btn-sm" style="height:36px;">검색</button>
            @if ($q !== '')
                <a href="{{ $listRoute }}" class="btn btn-ghost btn-sm" style="height:36px;">초기화</a>
            @endif
            <a href="{{ route('admin.keyword-hub') }}" class="btn btn-ghost btn-sm" style="height:36px;">돌아가기</a>
        </form>
    </div>
</div>

<div class="card p-5">
    <div style="overflow-x:auto;">
        <table class="w-full" style="font-size:var(--fs-xs);border-collapse:collapse;">
            <thead>
                <tr class="text-muted-soft" style="text-align:left;border-bottom:1px solid var(--color-hairline);">
                    <th style="padding:8px 6px;">키워드</th>
                    <th style="padding:8px 6px;">카테고리</th>
                    @if (! $isShopping)
                        <th style="padding:8px 6px;">지역</th>
                    @endif
                    <th style="padding:8px 6px;text-align:right;">{{ $isShopping ? '월 검색량' : '월 검색량' }}</th>
                    @if ($isShopping)
                        <th style="padding:8px 6px;text-align:right;">상품 수</th>
                        <th style="padding:8px 6px;text-align:right;">6개월 시장</th>
                    @endif
                    <th style="padding:8px 6px;">경쟁</th>
                    <th style="padding:8px 6px;text-align:right;">발행일</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($docs as $doc)
                    @php
                        $cat = $doc->category;
                        $catName = $cat ? collect([$cat->parent?->parent?->name, $cat->parent?->name, $cat->name])->filter()->implode(' › ') : '—';
                        $date = ($doc->refreshed_at ?? null) ?: ($doc->updated_at ?? $doc->created_at);
                    @endphp
                    <tr style="border-bottom:1px solid var(--color-hairline-soft);">
                        <td style="padding:7px 6px;">
                            <a href="{{ $doc->shareUrl() }}" target="_blank" rel="noopener" class="text-ink font-semibold" style="text-decoration:none;">{{ $doc->keyword }}</a>
                        </td>
                        <td style="padding:7px 6px;" class="text-muted">{{ $catName }}</td>
                        @if (! $isShopping)
                            <td style="padding:7px 6px;" class="text-muted">{{ $doc->region ?? '—' }}</td>
                        @endif
                        <td style="padding:7px 6px;text-align:right;" class="font-mono">{{ number_format((int) ($isShopping ? $doc->monthly_search : $doc->monthly_total)) }}</td>
                        @if ($isShopping)
                            <td style="padding:7px 6px;text-align:right;" class="font-mono">{{ number_format((int) $doc->item_count) }}</td>
                            <td style="padding:7px 6px;text-align:right;" class="font-mono">{{ number_format((int) $doc->revenue_6m) }}원</td>
                        @endif
                        <td style="padding:7px 6px;" class="text-muted">{{ $doc->comp_idx ?? '—' }}</td>
                        <td style="padding:7px 6px;text-align:right;" class="text-muted-soft">{{ $date?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $isShopping ? 7 : 6 }}" class="text-muted-soft text-center" style="padding:28px;">발행된 문서가 없습니다.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3">{{ $docs->links() }}</div>
</div>
@endsection
