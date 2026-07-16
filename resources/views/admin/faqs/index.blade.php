@extends('admin.layout')
@section('page-title', 'FAQ 관리')

@section('page-actions')
    <a href="{{ route('admin.faqs.create') }}" class="btn btn-primary btn-sm">+ 새 FAQ</a>
@endsection

@section('admin-content')
<x-console.page-head title="FAQ" desc="자주 묻는 질문 작성·게시 관리 · 카테고리·정렬 순서를 지정합니다" />

<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:820px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);border-bottom:1px solid var(--color-hairline-soft);">
                    <th class="text-center px-4 py-3 font-semibold" style="width:70px;">게시</th>
                    <th class="text-left px-3 py-3 font-semibold" style="width:150px;">카테고리</th>
                    <th class="text-left px-3 py-3 font-semibold">질문</th>
                    <th class="text-right px-3 py-3 font-semibold" style="width:64px;">정렬</th>
                    <th class="text-right px-3 py-3 font-semibold" style="width:64px;">조회</th>
                    <th class="text-right px-4 py-3 font-semibold" style="width:120px;">관리</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($faqs as $faq)
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        <td class="text-center px-4 py-3">
                            <form method="POST" action="{{ route('admin.faqs.toggle', $faq) }}">
                                @csrf
                                <label class="rf-switch"><input type="checkbox" onchange="this.form.submit()" {{ $faq->is_published ? 'checked' : '' }}><span class="rf-track"></span></label>
                            </form>
                        </td>
                        <td class="px-3 py-3"><span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">{{ $faq->category }}</span></td>
                        <td class="px-3 py-3"><a href="{{ route('admin.faqs.edit', $faq) }}" class="text-ink font-medium hover:underline" style="font-size:var(--fs-xs);">{{ $faq->question }}</a></td>
                        <td class="px-3 py-3 text-right text-muted-soft" style="font-size:var(--fs-xs);">{{ $faq->sort_order }}</td>
                        <td class="px-3 py-3 text-right text-muted" style="font-size:var(--fs-xs);">{{ number_format($faq->views) }}</td>
                        <td class="px-4 py-3 text-right" style="white-space:nowrap;">
                            <a href="{{ route('admin.faqs.edit', $faq) }}" class="btn btn-secondary btn-sm">수정</a>
                            <form method="POST" action="{{ route('admin.faqs.destroy', $faq) }}" style="display:inline;" onsubmit="return confirm('이 FAQ를 삭제할까요?');">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-error);">삭제</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted-soft" style="padding:48px;font-size:var(--fs-xs);">등록된 FAQ가 없습니다.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-4">{{ $faqs->links() }}</div>
@endsection
