{{-- 어드민 환경설정 > 커스텀 코드 → 모든 공개 페이지 <head> 주입(캐시). 관리자 신뢰 입력이라 원문 그대로 출력. --}}
{!! \App\Models\AppSetting::customHead() !!}
