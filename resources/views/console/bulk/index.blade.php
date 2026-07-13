@extends('console.layout')
@section('page-title', '키워드 대량 분석')

@section('console-content')
@php
    $statusBadge = fn ($s) => match ($s) {
        'done' => ['완료', 'var(--color-success)'], 'processing' => ['수집 중', 'var(--color-accent)'],
        'failed' => ['실패', 'var(--color-error)'], default => ['대기', 'var(--color-muted)'],
    };
@endphp

@if (session('status'))
    <div class="card-soft px-4 py-3 mb-4 text-muted" style="font-size:var(--fs-xs);">{{ session('status') }}</div>
@endif

{{-- 입력 --}}
<form method="POST" action="{{ route('console.bulk.store') }}" enctype="multipart/form-data" class="card p-5 mb-6" id="bulk-form">
    @csrf
    <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-xs);">키워드 대량 분석 <span class="text-muted-soft" style="font-weight:400;">최대 500개 · 검색량·발행량·포화·성별연령·요일·섹션배치</span></div>

    <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;display:block;margin-bottom:6px;">키워드 (줄바꿈 또는 쉼표 구분)</label>
    <textarea name="keywords" rows="6" placeholder="강남 맛집&#10;제주 호텔&#10;다이어트 보조제" class="input" style="width:100%;font-size:var(--fs-sm);resize:vertical;min-height:440px;"></textarea>

    <div class="flex items-center gap-4 flex-wrap mt-4">
        <div>
            <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;display:block;margin-bottom:6px;">또는 엑셀/CSV 업로드 <span class="text-muted-soft" style="font-weight:400;">(첫 열 = 키워드, 헤더 자동 스킵)</span></label>
            <input type="file" name="file" accept=".xlsx,.xls,.csv,.txt" style="font-size:var(--fs-xs);">
        </div>
        <label class="inline-flex items-center gap-2" style="font-size:var(--fs-xs);cursor:pointer;">
            <span class="rf-switch"><input type="checkbox" name="include_serp" value="1" checked><span class="rf-track"></span></span>
            PC/Mobile 섹션배치 포함 <span class="text-muted-soft">(정확·느림, 키워드당 ~8초)</span>
        </label>
        <button type="submit" class="btn btn-primary" style="height:40px;padding:0 22px;margin-left:auto;">분석 시작</button>
    </div>
    <p class="text-muted-soft mt-3" style="font-size:var(--fs-xs);">* 구글월별 검색량은 구글 애즈 API 연동 후 추가됩니다(현재 네이버 데이터만). 수집은 브라우저를 열어둔 상태에서 진행됩니다.</p>
</form>

{{-- 최근 분석 --}}
<div class="card overflow-hidden">
    <div class="px-5 py-4 text-ink font-semibold" style="font-size:var(--fs-xs);">최근 대량 분석</div>
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:640px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);border-top:1px solid var(--color-hairline-soft);">
                    <th class="text-left px-5 py-2.5 font-semibold">이름</th>
                    <th class="text-center px-3 py-2.5 font-semibold" style="width:90px;">상태</th>
                    <th class="text-right px-3 py-2.5 font-semibold" style="width:140px;">진행</th>
                    <th class="text-right px-3 py-2.5 font-semibold" style="width:120px;">생성</th>
                    <th class="text-right px-5 py-2.5 font-semibold" style="width:160px;">작업</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($batches as $b)
                    @php [$sl, $sc] = $statusBadge($b->status); @endphp
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        <td class="px-5 py-3"><a href="{{ route('console.bulk.show', $b) }}" class="text-ink hover:underline" style="font-size:var(--fs-xs);">{{ $b->name ?: '('.$b->total.'개)' }}</a></td>
                        <td class="text-center px-3 py-3" style="font-size:var(--fs-xs);color:{{ $sc }};font-weight:600;">{{ $sl }}</td>
                        <td class="px-3 py-3 text-right text-muted" style="font-size:var(--fs-xs);">{{ $b->done + $b->failed }} / {{ $b->total }} @if ($b->failed)<span style="color:var(--color-error);">(실패 {{ $b->failed }})</span>@endif</td>
                        <td class="px-3 py-3 text-right text-muted-soft" style="font-size:var(--fs-xs);">{{ $b->created_at->format('m-d H:i') }}</td>
                        <td class="px-5 py-3 text-right">
                            @if ($b->status === 'done')
                                <a href="{{ route('console.bulk.export', $b) }}" class="btn btn-secondary btn-sm" style="height:30px;">엑셀</a>
                            @else
                                <a href="{{ route('console.bulk.show', $b) }}" class="btn btn-secondary btn-sm" style="height:30px;">이어보기</a>
                            @endif
                            <form method="POST" action="{{ route('console.bulk.destroy', $b) }}" class="inline" onsubmit="return confirm('삭제할까요?');">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-muted-soft hover:text-ink" style="font-size:var(--fs-xs);text-decoration:underline;margin-left:6px;">삭제</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center" style="padding:32px;color:var(--color-muted);font-size:var(--fs-xs);">아직 대량 분석 내역이 없습니다.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
