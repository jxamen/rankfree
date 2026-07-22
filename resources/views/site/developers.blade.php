@extends('layouts.site')

{{-- 타이틀·디스크립션은 메뉴관리 > 사이트 SEO(route: developers)에서 설정 --}}
{{-- 문서 본문은 partials/developers-doc(공용) — 콘솔 /console/developers 와 내용 공유 --}}

@section('content')
<section class="container-page" style="padding-top:48px;padding-bottom:96px;max-width:880px;">
    <div class="badge mb-4 border border-hairline">개발자 문서</div>
    <h1 class="font-display text-ink" style="font-size:clamp(28px,4vw,40px);line-height:1.1;">랭크프리 API</h1>
    @include('partials.developers-doc')
</section>
@endsection
