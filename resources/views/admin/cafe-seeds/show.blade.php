@extends('admin.layout')
@section('page-title', '수집 글 상세')

@section('page-actions')
    <a href="{{ $article->url }}" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">원글 보기 ↗</a>
    <a href="{{ route('admin.cafe-seeds') }}" class="btn btn-secondary btn-sm">← 목록</a>
@endsection

@section('crumb-parent', 'admin.cafe-seeds')

@section('admin-content')
<x-console.page-head :title="$article->title" desc="{{ $article->writer }} · {{ $article->wrote_at?->timezone('Asia/Seoul')->format('Y-m-d H:i') }} · 조회 {{ number_format($article->read_count) }} · 댓글 {{ $article->comment_count }} · 수집 {{ $article->crawled_at?->timezone('Asia/Seoul')->format('Y-m-d H:i') }}" />

{{-- 본문 --}}
<div class="card p-5 mb-4">
    <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">본문</div>
    @if ($article->body)
        <div class="text-body" style="font-size:var(--fs-sm);line-height:1.8;white-space:pre-wrap;">{{ $article->body }}</div>
    @else
        <div class="text-muted-soft" style="font-size:var(--fs-xs);">본문이 수집되지 않았습니다 (다음 수집에서 채워집니다).</div>
    @endif
</div>

{{-- 글밥 전환·사용 이력 --}}
<div class="card p-5 mb-4">
    <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">글밥(재작성 소재) 상태</div>
    @if ($article->seed)
        <div class="text-muted mb-3" style="font-size:var(--fs-xs);">
            {{ $article->seeded_at?->timezone('Asia/Seoul')->format('Y-m-d H:i') }} 글밥 전환 ·
            사용 <b class="text-ink font-mono">{{ $article->seed->used_count }}</b>회
            @if ($article->seed->last_used_at) · 최근 사용 {{ $article->seed->last_used_at->timezone('Asia/Seoul')->format('Y-m-d H:i') }} @endif
        </div>
        @if ($article->seed->usages->isNotEmpty())
            <table class="w-full" style="min-width:600px;">
                <thead>
                    <tr class="text-muted" style="font-size:var(--fs-xs);border-bottom:1px solid var(--color-hairline-soft);">
                        <th class="text-left py-2 font-semibold" style="width:140px;">사용 시각</th>
                        <th class="text-left py-2 font-semibold" style="width:120px;">페르소나</th>
                        <th class="text-left py-2 font-semibold" style="width:70px;">형태</th>
                        <th class="text-left py-2 font-semibold">재작성 결과물</th>
                        <th class="text-left py-2 font-semibold" style="width:90px;">AI</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($article->seed->usages as $u)
                        <tr style="border-top:1px solid var(--color-hairline-soft);font-size:var(--fs-xs);">
                            <td class="py-2 text-muted">{{ $u->created_at->timezone('Asia/Seoul')->format('Y-m-d H:i') }}</td>
                            <td class="py-2 text-body">{{ $u->persona?->nickname ?? '—' }}</td>
                            <td class="py-2 text-muted">{{ $u->used_for === 'post' ? '글' : '댓글' }}</td>
                            <td class="py-2">
                                @if ($u->post)
                                    <a href="{{ url('/community/post/'.$u->post->id) }}" target="_blank" rel="noopener" class="text-body">{{ \Illuminate\Support\Str::limit($u->post->title, 50) }} ↗</a>
                                @else
                                    <span class="text-muted-soft">댓글 #{{ $u->comment_id ?? '—' }}</span>
                                @endif
                            </td>
                            <td class="py-2 text-muted">{{ $u->provider ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="text-muted-soft" style="font-size:var(--fs-xs);">아직 재작성에 사용되지 않았습니다.</div>
        @endif
    @else
        <div class="text-muted-soft" style="font-size:var(--fs-xs);">아직 글밥으로 전환되지 않았습니다{{ $article->body ? '' : ' (본문 수집 후 전환됩니다)' }}.</div>
    @endif
</div>

{{-- 댓글 --}}
<div class="card overflow-hidden">
    <div class="p-5 pb-3 text-ink font-semibold" style="font-size:var(--fs-sm);">수집 댓글 {{ $article->comments->count() }}건</div>
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:800px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);border-bottom:1px solid var(--color-hairline-soft);">
                    <th class="text-left px-4 py-2 font-semibold" style="width:120px;">작성자</th>
                    <th class="text-left px-3 py-2 font-semibold">내용</th>
                    <th class="text-left px-3 py-2 font-semibold" style="width:130px;">작성일</th>
                    <th class="text-center px-3 py-2 font-semibold" style="width:70px;">글밥</th>
                    <th class="text-right px-4 py-2 font-semibold" style="width:110px;">사용(최근)</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($article->comments as $c)
                    <tr style="border-top:1px solid var(--color-hairline-soft);font-size:var(--fs-xs);">
                        <td class="px-4 py-2 text-muted" style="vertical-align:top;">{{ $c->parent_comment_id ? '↳ ' : '' }}{{ $c->writer ?: '—' }}</td>
                        <td class="px-3 py-2 text-body" style="line-height:1.6;{{ $c->is_deleted ? 'opacity:.5;' : '' }}">{{ $c->content !== '' ? $c->content : '(내용 없음)' }}</td>
                        <td class="px-3 py-2 text-muted" style="vertical-align:top;">{{ $c->wrote_at?->timezone('Asia/Seoul')->format('Y-m-d H:i') ?? '—' }}</td>
                        <td class="px-3 py-2 text-center" style="vertical-align:top;">
                            @if ($c->seed_id)<span class="text-success">전환</span>@else<span class="text-muted-soft">—</span>@endif
                        </td>
                        <td class="px-4 py-2 text-right text-muted" style="vertical-align:top;">
                            @if ($c->seed && $c->seed->used_count > 0)
                                <span class="font-mono text-ink">{{ $c->seed->used_count }}회</span>
                                <span class="text-muted-soft">{{ $c->seed->last_used_at?->timezone('Asia/Seoul')->format('m-d') }}</span>
                            @else
                                <span class="text-muted-soft">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted-soft" style="padding:32px;font-size:var(--fs-xs);">수집된 댓글이 없습니다.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
