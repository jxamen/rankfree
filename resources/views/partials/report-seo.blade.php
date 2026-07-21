{{-- 분석 공유 리포트 SEO/AEO/GEO 구조화 데이터 — 검색·답변엔진·생성엔진 최적화.
     BreadcrumbList + Article + (선택)FAQPage. 공개 색인 대상(1회성 분석)에만 사용.
     입력:
       $seoTitle   리포트 제목(문자열)
       $seoDesc    설명(문자열)
       $seoSection 분석 유형 라벨(예: '키워드 분석')
       $seoDate    Carbon|null (분석일 — datePublished/Modified)
       $seoFaq     [['q'=>질문, 'a'=>답변], …] — AEO/GEO 답변 추출용(비면 FAQ 생략)
       $seoCrumbs  [['name'=>이름, 'url'=>주소], …]|null — 홈과 제목 사이 중간 경로(비면 $seoSection 1단)
     JSON_HEX_* 필수: 소재 텍스트가 </script> 로 스크립트 블록을 탈출하지 못하게 막는다. --}}
@php
    $__f = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $__url = url()->current();
    $__iso = ($seoDate ?? null)?->toIso8601String();
    $__faq = collect($seoFaq ?? [])->filter(fn ($x) => ! empty($x['q']) && ! empty($x['a']))->values();
    $__section = $seoSection ?? '분석 리포트';
    $__crumbs = [['name' => '홈', 'item' => url('/')]];
    foreach (collect($seoCrumbs ?? [])->filter(fn ($c) => ! empty($c['name'])) as $c) {
        $__crumbs[] = ['name' => $c['name'], 'item' => $c['url'] ?? $__url];
    }
    if (empty($seoCrumbs)) {
        $__crumbs[] = ['name' => $__section, 'item' => $__url];
    }
    $__crumbs[] = ['name' => $seoTitle, 'item' => $__url];
@endphp
@push('head')
<script type="application/ld+json">{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => collect($__crumbs)->values()->map(fn ($c, $i) => [
        '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c['name'], 'item' => $c['item'],
    ])->all(),
], $__f) !!}</script>
<script type="application/ld+json">{!! json_encode(array_filter([
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => \Illuminate\Support\Str::limit($seoTitle, 110, ''),
    'description' => $seoDesc,
    'url' => $__url,
    'mainEntityOfPage' => $__url,
    'inLanguage' => 'ko-KR',
    'articleSection' => $__section,
    'datePublished' => $__iso,
    'dateModified' => $__iso,
    'author' => ['@type' => 'Organization', 'name' => '랭크프리', 'url' => url('/')],
    'publisher' => ['@type' => 'Organization', 'name' => '랭크프리', 'logo' => ['@type' => 'ImageObject', 'url' => asset('icon-512.png')]],
    'image' => asset('og-image.png'),
    'isAccessibleForFree' => true,
], fn ($v) => $v !== null && $v !== ''), $__f) !!}</script>
@if ($__faq->isNotEmpty())
<script type="application/ld+json">{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => $__faq->map(fn ($x) => [
        '@type' => 'Question',
        'name' => $x['q'],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $x['a']],
    ])->all(),
], $__f) !!}</script>
@endif
@endpush
