@extends('layouts.site')
{{-- 타이틀·디스크립션은 메뉴관리 > 사이트 SEO(route: community)에서 설정 --}}
@section('follow-theme', '1')

@section('content')
<section class="container-page py-10 lg:py-14">
    <div class="flex items-end justify-between flex-wrap gap-3 mb-6">
        <div>
            <h1 class="font-display text-ink" style="font-size:clamp(26px,3vw,34px);line-height:1.2;">커뮤니티</h1>
            <p class="text-muted mt-1" style="font-size:var(--fs-sm);">네이버 마케팅 노하우와 경험을 나누는 공간 · 글 {{ number_format($totalPosts) }}개</p>
        </div>
        @auth
            <a href="{{ route('community.create', ['cat' => $category?->slug]) }}" class="btn btn-primary">＋ 글쓰기</a>
        @else
            <a href="{{ route('login', ['from' => url()->current()]) }}" class="btn btn-primary">로그인하고 글쓰기</a>
        @endauth
    </div>

    <div class="grid gap-6 lg:grid-cols-[220px_1fr]">
        {{-- 카테고리 사이드 --}}
        <aside>
            <div class="card overflow-hidden">
                <a href="{{ route('community') }}" class="flex items-center justify-between px-4 py-3 hover:bg-surface-soft transition {{ ! $category ? 'bg-surface-soft' : '' }}" style="font-size:var(--fs-sm);border-bottom:1px solid var(--color-hairline-soft);">
                    <span class="text-ink font-semibold">전체</span>
                    <span class="text-muted-soft" style="font-size:var(--fs-xs);">{{ number_format($totalPosts) }}</span>
                </a>
                @foreach ($categories as $cat)
                    <a href="{{ route('community', ['cat' => $cat->slug]) }}" class="flex items-center justify-between px-4 py-3 hover:bg-surface-soft transition {{ $category?->id === $cat->id ? 'bg-surface-soft' : '' }}" style="font-size:var(--fs-sm);border-top:1px solid var(--color-hairline-soft);">
                        <span class="{{ $category?->id === $cat->id ? 'text-ink font-semibold' : 'text-body' }}">{{ $cat->icon }} {{ $cat->name }}</span>
                        <span class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $cat->posts_count }}</span>
                    </a>
                @endforeach
            </div>
        </aside>

        {{-- 글 목록 --}}
        <div>
            <div class="card overflow-hidden">
                @forelse ($posts as $post)
                    <a href="{{ route('community.show', $post) }}" class="block px-5 py-4 hover:bg-surface-soft transition" style="border-top:{{ $loop->first ? '0' : '1px solid var(--color-hairline-soft)' }};">
                        <div class="flex items-center gap-2 mb-1.5">
                            <span class="badge" style="font-size:var(--fs-xs);padding:1px 8px;">{{ $post->category->icon }} {{ $post->category->name }}</span>
                            @if ($post->is_pinned)<span style="color:var(--color-badge-orange);font-size:var(--fs-xs);">📌</span>@endif
                        </div>
                        <div class="text-ink font-semibold" style="font-size:var(--fs-base);line-height:1.4;">{{ $post->title }}</div>
                        <p class="text-muted mt-1" style="font-size:var(--fs-xs);line-height:1.5;">{{ $post->excerpt() }}</p>
                        <div class="flex items-center gap-3 mt-2 text-muted-soft" style="font-size:var(--fs-xs);">
                            <span class="inline-flex items-center gap-1.5">
                                <span style="width:18px;height:18px;flex:none;border-radius:50%;display:grid;place-items:center;background:{{ $post->authorColor() }};color:#fff;font-size:10px;font-weight:700;">{{ $post->authorInitial() }}</span>
                                {{ $post->authorName() }}
                            </span>
                            <span>· {{ $post->created_at->diffForHumans() }}</span>
                            <span>· 👁 {{ number_format($post->views) }}</span>
                            <span>· ♥ {{ number_format($post->likes_count) }}</span>
                            <span>· 💬 {{ number_format($post->comments_count) }}</span>
                        </div>
                    </a>
                @empty
                    <div class="text-center text-muted-soft" style="padding:60px 20px;font-size:var(--fs-sm);">아직 글이 없습니다. 첫 글을 남겨보세요!</div>
                @endforelse
            </div>
            <div class="mt-5">{{ $posts->links() }}</div>
        </div>
    </div>
</section>
@endsection
