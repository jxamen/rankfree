@extends('admin.layout')
@section('page-title', '글밥 (수집 소재)')

@section('page-actions')
    <a href="{{ route('admin.personas') }}" class="btn btn-secondary btn-sm">← 페르소나 관리</a>
@endsection

@section('admin-content')
<p class="text-muted mb-4" style="font-size:var(--fs-xs);">
    다른 커뮤니티에서 수집한 글감을 등록해 두면, 페르소나가 이걸 <b>소재로 삼아 말투를 변형</b>해 글·댓글을 작성합니다.
    (Claude API 연결 시 자연스럽게 재작성, 미연결 시 소재를 가볍게 변형해 사용)
</p>

{{-- 탭 --}}
<div class="flex items-center gap-2 mb-4">
    <a href="{{ route('admin.community-seeds', ['kind' => 'post']) }}" class="badge {{ $kind === 'post' ? '' : '' }}" style="font-size:var(--fs-xs);padding:5px 12px;{{ $kind === 'post' ? 'background:var(--color-ink);color:var(--color-canvas);' : '' }}">글 소재 {{ $postCount }}</a>
    <a href="{{ route('admin.community-seeds', ['kind' => 'comment']) }}" class="badge" style="font-size:var(--fs-xs);padding:5px 12px;{{ $kind === 'comment' ? 'background:var(--color-ink);color:var(--color-canvas);' : '' }}">댓글 소재 {{ $commentCount }}</a>
</div>

{{-- 대량 등록 --}}
<div class="card p-5 mb-5">
    <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">글밥 대량 등록</div>
    <form method="POST" action="{{ route('admin.community-seeds.store') }}">
        @csrf
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
            <div>
                <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">종류</label>
                <select name="kind" class="input">
                    <option value="post" @selected($kind === 'post')>글 소재 (제목+본문)</option>
                    <option value="comment" @selected($kind === 'comment')>댓글 소재</option>
                </select>
            </div>
            <div>
                <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">카테고리(선택)</label>
                <select name="category_id" class="input">
                    <option value="">전체 공용</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}">{{ trim($cat->icon.' '.$cat->name) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">출처 메모(선택)</label>
                <input name="source" class="input" maxlength="80" placeholder="예: OO카페">
            </div>
        </div>
        <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">
            내용 — 여러 개는 <b>--- (하이픈 3개)</b> 줄로 구분. 글 소재는 <b>첫 줄=제목, 나머지=본문</b>.
        </label>
        <textarea name="bulk" class="input" style="height:200px;padding:12px 14px;line-height:1.7;font-size:var(--fs-xs);" required placeholder="플레이스 순위 어떻게 올리나요?&#10;요즘 리뷰 관리가 잘 안돼서 고민입니다...&#10;---&#10;다음 글감 제목&#10;다음 글감 본문..."></textarea>
        <div class="mt-3">
            <button type="submit" class="btn btn-primary">등록</button>
        </div>
    </form>
</div>

{{-- 목록 --}}
<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:800px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);border-bottom:1px solid var(--color-hairline-soft);">
                    <th class="text-left px-4 py-3 font-semibold">소재</th>
                    <th class="text-left px-3 py-3 font-semibold" style="width:120px;">카테고리</th>
                    <th class="text-left px-3 py-3 font-semibold" style="width:100px;">출처</th>
                    <th class="text-right px-3 py-3 font-semibold" style="width:70px;">사용</th>
                    <th class="text-center px-3 py-3 font-semibold" style="width:70px;">사용중</th>
                    <th class="text-right px-4 py-3 font-semibold" style="width:70px;">관리</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($seeds as $seed)
                    <tr style="border-top:1px solid var(--color-hairline-soft);{{ $seed->is_active ? '' : 'opacity:.5;' }}">
                        <td class="px-4 py-3" style="max-width:420px;">
                            @if ($seed->title)<div class="text-ink font-semibold" style="font-size:var(--fs-xs);">{{ $seed->title }}</div>@endif
                            <div class="text-muted truncate" style="font-size:var(--fs-xs);max-width:420px;">{{ \Illuminate\Support\Str::limit($seed->body, 90) }}</div>
                        </td>
                        <td class="px-3 py-3 text-muted" style="font-size:var(--fs-xs);">{{ $seed->category?->name ?? '공용' }}</td>
                        <td class="px-3 py-3 text-muted-soft" style="font-size:var(--fs-xs);">{{ $seed->source ?: '—' }}</td>
                        <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ $seed->used_count }}</td>
                        <td class="px-3 py-3 text-center">
                            <form method="POST" action="{{ route('admin.community-seeds.toggle', $seed) }}">
                                @csrf
                                <button type="submit" class="badge" style="font-size:var(--fs-xs);padding:2px 9px;cursor:pointer;{{ $seed->is_active ? 'background:color-mix(in srgb,var(--color-success) 14%,var(--color-canvas));color:var(--color-success);' : '' }}">{{ $seed->is_active ? 'ON' : 'OFF' }}</button>
                            </form>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <form method="POST" action="{{ route('admin.community-seeds.destroy', $seed) }}" onsubmit="return confirm('삭제할까요?');">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-muted-soft hover:text-error" style="font-size:var(--fs-xs);background:none;border:0;cursor:pointer;">삭제</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted-soft" style="padding:40px;font-size:var(--fs-xs);">등록된 글밥이 없습니다. 위에서 수집한 글감을 붙여넣어 등록하세요.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-4">{{ $seeds->links() }}</div>
@endsection
