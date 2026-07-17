{{-- GA4 미연동 안내 — 호스트가 config('ga4-insights.setup_help')(HTML)로 앱별 안내를 넣을 수 있음. --}}
<div class="ga4-card">
    <div style="font-weight:700;margin-bottom:8px;">GA4가 아직 연결되지 않았습니다</div>
    @if ($help = config('ga4-insights.setup_help'))
        <div class="ga4-sub" style="line-height:1.9;">{!! $help !!}</div>
    @else
        <ol style="color:var(--ga4-muted);font-size:var(--ga4-fs-xs, 14px);line-height:2;padding-left:18px;">
            <li>GA4 <b>속성 ID(숫자)</b>를 설정하세요.</li>
            <li>Google Analytics Data API 접근 권한(서비스 계정 또는 관리자 OAuth)을 연결하고, 해당 계정을 GA4 속성에 <b>뷰어</b>로 추가하세요.</li>
            <li>연결되면 이 화면에 방문 데이터가 표시됩니다.</li>
        </ol>
    @endif
</div>
