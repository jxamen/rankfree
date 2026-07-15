{{-- 로그인 회원 → 콘솔 셸 / 비로그인 → 공개 사이트(SEO) --}}
@extends(auth()->check() ? 'console.layout' : 'layouts.site')
@section('title', $post->title.' · 커뮤니티 · 랭크프리')
@section('page-title', '커뮤니티')

@auth
    @section('console-content')
        @include('community._show_body')
    @endsection
@else
    {{-- SEO (게스트 렌더에만) — canonical·og article·본문 첫 이미지·구조화 데이터.
         JSON_HEX_* 필수: 글 제목 등 사용자 입력이 </script> 로 스크립트 블록을 탈출하지 못하게 막는다. --}}
    @php
        $__ldFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $__bodyHtml = $post->bodyHtml();
        $__bodyText = mb_substr(preg_replace('/\s+/u', ' ', trim(strip_tags($__bodyHtml))), 0, 500);
        preg_match('/<img[^>]+src="([^"]+)"/i', $__bodyHtml, $__im);
        $__src = $__im[1] ?? '';
        // data: URI 는 제외, //host 프로토콜 상대는 스킴 보정, 상대경로는 절대화
        $__img = match (true) {
            $__src === '' || str_starts_with($__src, 'data:') => null,
            str_starts_with($__src, '//') => request()->getScheme().':'.$__src,
            str_starts_with($__src, 'http') => $__src,
            default => url($__src),
        };
    @endphp
    @section('description', mb_substr($__bodyText, 0, 160) ?: $post->title)
    @section('canonical', route('community.show', $post))
    @section('og-type', 'article')
    @if ($__img)
        @section('og-image', $__img)
    @endif
    @push('head')
{{-- BreadcrumbList — 홈 > 커뮤니티 > 카테고리 > 글 --}}
<script type="application/ld+json">{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => array_values(array_filter([
        ['@type' => 'ListItem', 'position' => 1, 'name' => '홈', 'item' => url('/')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => '커뮤니티', 'item' => route('community')],
        $post->category ? ['@type' => 'ListItem', 'position' => 3, 'name' => $post->category->name, 'item' => route('community', ['cat' => $post->category->slug])] : null,
        ['@type' => 'ListItem', 'position' => $post->category ? 4 : 3, 'name' => $post->title, 'item' => route('community.show', $post)],
    ])),
], $__ldFlags) !!}</script>
{{-- DiscussionForumPosting — 실사용자 작성 글에만 출력(구글 포럼 마크업은 실제 사람의 UGC 전용. 페르소나 글은 미출력). --}}
@if ($post->author_type === 'user' && $post->user)
<script type="application/ld+json">{!! json_encode(array_filter([
    '@context' => 'https://schema.org',
    '@type' => 'DiscussionForumPosting',
    'headline' => $post->title,
    'text' => $__bodyText,
    'url' => route('community.show', $post),
    'author' => ['@type' => 'Person', 'name' => $post->authorName()],
    'datePublished' => $post->created_at?->toIso8601String(),
    'articleSection' => $post->category?->name,
    'image' => $__img,
    'commentCount' => (int) $post->comments_count,
    'interactionStatistic' => [
        ['@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/ViewAction', 'userInteractionCount' => (int) $post->views],
    ],
], fn ($v) => $v !== null && $v !== ''), $__ldFlags) !!}</script>
@endif
    @endpush
    @section('follow-theme', '1')
    @section('content')
        @include('community._show_body')
    @endsection
@endauth
