@extends('layouts.site')
@section('title', $post->title.' · 커뮤니티 · rankfree')
@section('follow-theme', '1')

@section('content')
@php
    $authorRow = function ($item, $sm = false) {
        $sz = $sm ? 24 : 32;
        return '<span style="width:'.$sz.'px;height:'.$sz.'px;flex:none;border-radius:50%;display:grid;place-items:center;background:'.$item->authorColor().';color:#fff;font-size:'.($sm ? 11 : 13).'px;font-weight:700;">'.e($item->authorInitial()).'</span>';
    };
@endphp
<section class="container-page py-10 lg:py-14" style="max-width:820px;">
    <a href="{{ route('community', ['cat' => $post->category->slug]) }}" class="text-muted hover:text-ink inline-flex items-center gap-1 mb-4" style="font-size:var(--fs-xs);">← {{ $post->category->icon }} {{ $post->category->name }}</a>

    {{-- 본문 --}}
    <article class="card p-6 lg:p-8 mb-5">
        <h1 class="font-display text-ink" style="font-size:clamp(22px,2.6vw,30px);line-height:1.3;">{{ $post->title }}</h1>
        <div class="flex items-center gap-3 mt-4 pb-4" style="border-bottom:1px solid var(--color-hairline-soft);">
            {!! $authorRow($post) !!}
            <div>
                <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">{{ $post->authorName() }}</div>
                <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $post->created_at->format('Y-m-d H:i') }} · 조회 {{ number_format($post->views) }}</div>
            </div>
            @if (auth()->id() && $post->author_type === 'user' && $post->user_id === auth()->id())
                <form method="POST" action="{{ route('community.destroy', $post) }}" class="ml-auto" onsubmit="return confirm('삭제할까요?');">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-muted-soft hover:text-error" style="font-size:var(--fs-xs);background:none;border:0;cursor:pointer;">삭제</button>
                </form>
            @endif
        </div>
        <div class="text-body mt-5" style="font-size:var(--fs-base);line-height:1.8;white-space:pre-wrap;">{{ $post->body }}</div>

        {{-- 좋아요 --}}
        <div class="mt-6 flex justify-center">
            @auth
                <button type="button" id="rf-like-btn" data-liked="{{ $liked ? '1' : '0' }}"
                        class="btn {{ $liked ? 'btn-primary' : 'btn-secondary' }}" style="min-width:120px;">
                    <span id="rf-like-ico">{{ $liked ? '♥' : '♡' }}</span> 좋아요 <span id="rf-like-count">{{ number_format($post->likes_count) }}</span>
                </button>
            @else
                <div class="btn btn-secondary" style="min-width:120px;pointer-events:none;">♡ 좋아요 {{ number_format($post->likes_count) }}</div>
            @endauth
        </div>
    </article>

    {{-- 댓글 --}}
    <div class="card p-6 lg:p-8">
        <div class="text-ink font-semibold mb-4" style="font-size:var(--fs-sm);">댓글 {{ number_format($post->comments_count) }}</div>

        @auth
            <form method="POST" action="{{ route('community.comment', $post) }}" class="flex gap-2 mb-6">
                @csrf
                <input name="body" class="input" style="flex:1;" placeholder="댓글을 남겨보세요" maxlength="2000" required>
                <button type="submit" class="btn btn-primary">등록</button>
            </form>
        @else
            <div class="card-soft px-4 py-3 mb-6 text-muted" style="font-size:var(--fs-xs);">
                <a href="{{ route('login', ['from' => url()->current()]) }}" class="text-accent hover:underline">로그인</a> 후 댓글을 남길 수 있습니다.
            </div>
        @endauth

        <div class="flex flex-col">
            @forelse ($comments as $comment)
                <div style="padding:14px 0;border-top:{{ $loop->first ? '0' : '1px solid var(--color-hairline-soft)' }};">
                    <div class="flex items-start gap-2.5">
                        {!! $authorRow($comment, true) !!}
                        <div style="min-width:0;flex:1;">
                            <div class="flex items-center gap-2">
                                <span class="text-ink font-semibold" style="font-size:var(--fs-xs);">{{ $comment->authorName() }}</span>
                                <span class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $comment->created_at->diffForHumans() }}</span>
                            </div>
                            <div class="text-body mt-1" style="font-size:var(--fs-sm);line-height:1.6;white-space:pre-wrap;">{{ $comment->body }}</div>
                            @foreach ($comment->replies as $reply)
                                <div class="flex items-start gap-2 mt-3" style="padding-left:12px;border-left:2px solid var(--color-hairline);">
                                    {!! $authorRow($reply, true) !!}
                                    <div style="min-width:0;">
                                        <div class="flex items-center gap-2">
                                            <span class="text-ink font-semibold" style="font-size:var(--fs-xs);">{{ $reply->authorName() }}</span>
                                            <span class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $reply->created_at->diffForHumans() }}</span>
                                        </div>
                                        <div class="text-body mt-1" style="font-size:var(--fs-sm);line-height:1.6;white-space:pre-wrap;">{{ $reply->body }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @empty
                <div class="text-center text-muted-soft" style="padding:24px;font-size:var(--fs-xs);">첫 댓글을 남겨보세요.</div>
            @endforelse
        </div>
    </div>
</section>

@auth
<script>
(function () {
    var btn = document.getElementById('rf-like-btn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        btn.disabled = true;
        fetch('{{ route('community.like', $post) }}', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
        }).then(function (r) { return r.json(); }).then(function (d) {
            document.getElementById('rf-like-count').textContent = Number(d.count).toLocaleString();
            document.getElementById('rf-like-ico').textContent = d.liked ? '♥' : '♡';
            btn.dataset.liked = d.liked ? '1' : '0';
            btn.classList.toggle('btn-primary', d.liked);
            btn.classList.toggle('btn-secondary', !d.liked);
        }).catch(function () {}).then(function () { btn.disabled = false; });
    });
})();
</script>
@endauth
@endsection
