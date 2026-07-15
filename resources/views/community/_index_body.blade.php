{{-- 커뮤니티 목록 본문 — 게스트(layouts.site)·회원(console.layout) 공용.
     게시판(카테고리) 이동은 좌측 콘솔 메뉴, 콘텐츠 영역은 공지사항처럼 게시물 목록만. --}}
<section class="py-10 lg:py-14 {{ auth()->check() ? '' : 'container-page' }}" style="padding-left:0;padding-right:0;">
    <div class="flex items-end justify-between flex-wrap gap-3 mb-5">
        <div>
            <h1 class="font-display text-ink" style="font-size:clamp(24px,2.8vw,32px);line-height:1.2;">
                {{ $category ? trim($category->icon.' '.$category->name) : '커뮤니티' }}
            </h1>
            <p class="text-muted mt-1" style="font-size:var(--fs-sm);">
                {{ $category?->description ?: '네이버 마케팅 노하우와 경험을 나누는 공간' }}
                · 글 {{ number_format($category ? $posts->total() : $totalPosts) }}개
            </p>
        </div>
        @auth
            <a href="{{ route('community.create', ['cat' => $category?->slug]) }}" class="btn btn-primary">＋ 글쓰기</a>
        @else
            <a href="{{ route('login', ['from' => url()->current()]) }}" class="btn btn-primary">로그인하고 글쓰기</a>
        @endauth
    </div>

    {{-- 비로그인(좌측 메뉴 없음)은 상단 칩으로 게시판 이동 --}}
    @guest
        <div class="flex flex-wrap gap-2 mb-5">
            <a href="{{ route('community') }}" class="badge" style="font-size:var(--fs-xs);padding:4px 12px;{{ ! $category ? 'background:var(--color-ink);color:var(--color-canvas);' : '' }}">전체</a>
            @foreach ($categories as $cat)
                <a href="{{ route('community', ['cat' => $cat->slug]) }}" class="badge" style="font-size:var(--fs-xs);padding:4px 12px;{{ $category?->id === $cat->id ? 'background:var(--color-ink);color:var(--color-canvas);' : '' }}">{{ trim($cat->icon.' '.$cat->name) }}</a>
            @endforeach
        </div>
    @endguest

    {{-- 글 목록 (테이블형 — 제목 좌측, 작성자·날짜·조회·좋아요 우측 정렬) --}}
    <div class="card overflow-hidden">
        {{-- 헤더 --}}
        <div class="flex items-center gap-3 px-5 py-2.5 text-muted-soft" style="font-size:var(--fs-xs);background:var(--color-surface-soft);">
            <span style="flex:1;min-width:0;">제목</span>
            <span class="hidden sm:block flex-none" style="width:130px;">작성자</span>
            <span class="hidden sm:block flex-none text-right" style="width:76px;">날짜</span>
            <span class="flex-none text-right" style="width:60px;">조회</span>
            <span class="flex-none text-right" style="width:56px;">좋아요</span>
        </div>
        @forelse ($posts as $post)
            <a href="{{ route('community.show', $post) }}" class="flex items-center gap-3 px-5 py-3.5 hover:bg-surface-soft transition" style="border-top:1px solid var(--color-hairline-soft);">
                @if ($post->is_pinned)<span title="고정" style="color:var(--color-badge-orange);font-size:var(--fs-xs);flex:none;">📌</span>@endif
                @unless ($category)
                    <span class="badge flex-none" style="font-size:var(--fs-xs);padding:1px 8px;">{{ trim($post->category->icon.' '.$post->category->name) }}</span>
                @endunless
                {{-- 제목 + 댓글수(제목 바로 옆) --}}
                <span class="flex items-center gap-1.5" style="flex:1;min-width:0;">
                    <span class="text-ink font-semibold truncate" style="min-width:0;font-size:var(--fs-sm);">{{ $post->title }}</span>
                    @if ($post->comments_count > 0)<span class="text-accent flex-none" style="font-size:var(--fs-xs);font-weight:600;">[{{ number_format($post->comments_count) }}]</span>@endif
                </span>
                {{-- 우측 정렬 메타 (테이블 컬럼) --}}
                <span class="items-center gap-1.5 flex-none hidden sm:inline-flex" style="width:130px;">
                    <span style="width:18px;height:18px;flex:none;border-radius:50%;display:grid;place-items:center;background:{{ $post->authorColor() }};color:#fff;font-size:10px;font-weight:700;">{{ $post->authorInitial() }}</span>
                    <span class="text-muted truncate" style="font-size:var(--fs-xs);">{{ $post->authorName() }}</span>
                </span>
                <span class="text-muted-soft flex-none text-right hidden sm:block" style="width:76px;font-size:var(--fs-xs);">{{ $post->created_at->format('y.m.d') }}</span>
                <span class="text-muted-soft flex-none text-right" style="width:60px;font-size:var(--fs-xs);">{{ number_format($post->views) }}</span>
                <span class="text-muted-soft flex-none text-right" style="width:56px;font-size:var(--fs-xs);">{{ number_format($post->likes_count) }}</span>
            </a>
        @empty
            <div class="text-center text-muted-soft" style="padding:64px 24px;font-size:var(--fs-sm);">아직 글이 없습니다. 첫 글을 남겨보세요!</div>
        @endforelse
    </div>
    <div class="mt-5">{{ $posts->links() }}</div>
</section>
