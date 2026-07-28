{{-- 어드민 환경설정 > 커스텀 코드 → 모든 페이지 <body> **바로 뒤** 주입(캐시). 관리자 신뢰 입력이라 원문 그대로 출력.
     GTM noscript(iframe) 처럼 head 가 아니라 body 첫머리에 있어야 하는 코드를 넣는다.
     custom-head 와 동일하게 ANALYTICS_EXCLUDE_IPS(.env) 에 등록된 IP 에는 출력하지 않는다(사무실 트래픽 집계 제외). --}}
@if (! in_array(request()->ip(), (array) config('rankfree.analytics_exclude_ips', []), true))
    {!! \App\Models\AppSetting::customBody() !!}
@endif
