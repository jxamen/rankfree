@extends('console.layout')
@section('page-title', '판매자 톡톡 연락처')

@section('page-actions')
    <a href="{{ route('console.talk-contacts.export', request()->only('q')) }}" class="btn btn-secondary btn-sm">
        <i class="fa-solid fa-download"></i> CSV 내보내기
    </a>
@endsection

@section('console-content')

<div class="mb-4">
    <div class="text-muted" style="font-size:var(--fs-xs);">셀러력 수집 중 확보한 판매자 스토어·톡톡 식별자 (슈퍼어드민 전용)</div>
    <div class="text-ink font-display mt-1" style="font-size:var(--fs-lg);">{{ number_format($total) }}건</div>
</div>

{{-- 검색 --}}
<form method="GET" class="flex gap-2 flex-wrap items-end mb-4">
    <div>
        <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">검색</label>
        <input name="q" value="{{ $q }}" class="input" style="width:280px;" placeholder="키워드 · 몰이름 · 톡톡아이디">
    </div>
    <button type="submit" class="btn btn-primary btn-sm">검색</button>
    @if ($q)
        <a href="{{ route('console.talk-contacts') }}" class="btn btn-ghost btn-sm">초기화</a>
    @endif
</form>

{{-- 목록 --}}
<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:880px;">
            <thead>
                <tr class="text-muted border-b border-hairline" style="font-size:var(--fs-xs);">
                    <th class="text-left font-medium px-4 py-3" style="width:56px;">No</th>
                    <th class="text-left font-medium px-4 py-3">키워드</th>
                    <th class="text-left font-medium px-4 py-3">몰이름</th>
                    <th class="text-right font-medium px-4 py-3" style="width:70px;">순위</th>
                    <th class="text-left font-medium px-4 py-3">톡톡아이디</th>
                    <th class="text-left font-medium px-4 py-3" style="width:150px;">수집일</th>
                </tr>
            </thead>
            <tbody>
                @php $rowNo = $rows->total() - (($rows->firstItem() ?? 1) - 1); @endphp
                @forelse ($rows as $r)
                    <tr class="border-b border-hairline hover:bg-surface-soft" style="font-size:var(--fs-sm);">
                        <td class="px-4 py-3 text-muted" style="font-size:var(--fs-xs);">{{ $rowNo - $loop->index }}</td>
                        <td class="px-4 py-3 text-ink">{{ $r->keyword }}</td>
                        <td class="px-4 py-3 text-body">{{ $r->mall_name ?: '—' }}</td>
                        <td class="px-4 py-3 text-right text-muted" style="font-variant-numeric:tabular-nums;">
                            {{ $r->rank > 0 ? $r->rank : '광고' }}
                        </td>
                        <td class="px-4 py-3">
                            <a href="https://talk.naver.com/ct/{{ $r->talk_id }}?frm=pss#nafullscreen"
                               onclick="window.open(this.href, 'rf-talktalk', 'width=420,height=700,noopener'); return false;"
                               class="text-ink font-medium hover:underline" title="톡톡 상담 열기">{{ $r->talk_id }}</a>
                        </td>
                        <td class="px-4 py-3 text-muted" style="font-size:var(--fs-xs);">
                            {{ optional($r->collected_at)->format('Y-m-d H:i') ?: '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-muted" style="font-size:var(--fs-sm);">
                            수집된 연락처가 없습니다. 확장에서 쇼핑 검색 상품을 수집하면 이곳에 쌓입니다.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">
    {{ $rows->links() }}
</div>

@endsection
