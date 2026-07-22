{{-- 어드민 환경설정 > 커스텀 코드 → 모든 공개 페이지 <head> 주입(캐시). 관리자 신뢰 입력이라 원문 그대로 출력.
     ANALYTICS_EXCLUDE_IPS(.env, 콤마 구분)에 등록된 IP 에는 출력하지 않는다 — 사무실 트래픽을 GA4 등 집계에서 제외. --}}
@if (! in_array(request()->ip(), (array) config('rankfree.analytics_exclude_ips', []), true))
    {!! \App\Models\AppSetting::customHead() !!}
@endif
