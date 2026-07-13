@extends('layouts.site')

{{-- 타이틀·디스크립션은 메뉴관리 > 사이트 SEO(route: privacy)에서 설정 --}}

@section('content')
<style>
    .doc-h2 { font-size:var(--fs-lg); line-height: 1.3; margin-top: 40px; margin-bottom: 10px; font-weight: 700; }
    .doc-p { color: var(--color-body); line-height: 1.75; margin-bottom: 10px; }
    .doc-ul { margin: 6px 0 10px; padding-left: 18px; }
    .doc-ul li { color: var(--color-body); line-height: 1.7; margin-bottom: 4px; }
    .doc-table { width: 100%; font-size:var(--fs-sm); border-collapse: collapse; margin: 8px 0; }
    .doc-table th { text-align: left; padding: 8px 10px; color: var(--color-muted); border-bottom: 1px solid var(--color-hairline); white-space: nowrap; }
    .doc-table td { padding: 9px 10px; border-bottom: 1px solid var(--color-hairline-soft); vertical-align: top; color: var(--color-body); }
</style>

<section class="container-page" style="padding-top:48px;padding-bottom:96px;max-width:820px;">
    <div class="badge mb-4 border border-hairline">약관</div>
    <h1 class="font-display text-ink" style="font-size:clamp(28px,4vw,40px);line-height:1.1;">개인정보처리방침</h1>
    <p class="text-muted" style="margin-top:12px;font-size:var(--fs-sm);">시행일: 2026년 7월 13일</p>

    <p class="doc-p" style="margin-top:24px;">
        랭크프리(rankfree.kr, 이하 "서비스")는 네이버 쇼핑·플레이스·키워드·상품 리뷰 분석을 제공하며,
        웹사이트와 크롬 확장 프로그램(RankFree)을 통해 이용됩니다. 본 방침은 서비스가 수집·이용하는
        개인정보와 크롬 확장 프로그램의 데이터 처리 방식을 설명합니다.
    </p>

    <h2 class="doc-h2 text-ink">1. 수집하는 항목</h2>
    <table class="doc-table">
        <tr><th>구분</th><th>항목</th><th>수집 시점</th></tr>
        <tr><td>회원 정보</td><td>이메일, 비밀번호(암호화 저장), 접속 로그</td><td>회원가입·로그인</td></tr>
        <tr><td>분석 요청 데이터</td><td>분석 대상 키워드·상품 URL·플레이스 정보, 이용자가 조회 중인 네이버 상품/검색 페이지의 공개 데이터(가격·리뷰·순위 등)</td><td>분석 실행 시</td></tr>
        <tr><td>분석 결과</td><td>순위·시장 규모·키워드·리뷰 감정분석 등 산출 결과 및 저장 내역</td><td>분석 저장 시</td></tr>
        <tr><td>연동 자격증명(선택)</td><td>네이버 검색광고 로그인 정보 등 이용자가 직접 등록한 값(<b>암호화 저장</b>)</td><td>이용자 등록 시</td></tr>
    </table>

    <h2 class="doc-h2 text-ink">2. 이용 목적</h2>
    <ul class="doc-ul">
        <li>회원 식별·로그인 유지 및 서비스 제공</li>
        <li>네이버 쇼핑·플레이스·키워드·상품 리뷰 분석 결과 산출 및 저장 내역 제공</li>
        <li>서비스 운영·개선, 부정 이용 방지</li>
    </ul>

    <h2 class="doc-h2 text-ink">3. 크롬 확장 프로그램(RankFree)의 데이터 처리</h2>
    <p class="doc-p">확장 프로그램은 <b>이용자가 직접 실행할 때만</b> 동작하며, 다음과 같이 데이터를 처리합니다.</p>
    <ul class="doc-ul">
        <li>이용자가 열람 중인 네이버 쇼핑·검색·상품·플레이스 페이지의 <b>공개 데이터</b>(상품·가격·리뷰·순위 등)를 읽어, 분석을 위해 서비스 서버(rankfree.kr)로 전송합니다.</li>
        <li>수집을 위해 백그라운드 탭을 열어 네이버 페이지를 조회할 수 있으며, 이는 이용자가 요청한 분석 수행 목적에 한합니다.</li>
        <li>로그인 토큰 등 최소한의 설정값을 브라우저 로컬 저장소(<span style="font-family:var(--font-mono);">chrome.storage.local</span>)에 저장합니다.</li>
        <li>이용자의 일반 브라우징 기록·비네이버 사이트 데이터는 <b>수집하지 않습니다.</b></li>
    </ul>

    <h2 class="doc-h2 text-ink">4. 보관 및 파기</h2>
    <ul class="doc-ul">
        <li>회원 정보는 <b>회원 탈퇴 시 지체 없이 파기</b>합니다(법령상 보존 의무가 있는 경우 해당 기간 보관).</li>
        <li>분석 저장 내역은 이용자가 삭제하거나 탈퇴 시 파기됩니다.</li>
        <li>브라우저 로컬 저장소의 값은 확장 프로그램 제거 또는 로그아웃 시 삭제됩니다.</li>
    </ul>

    <h2 class="doc-h2 text-ink">5. 제3자 제공 및 처리 위탁</h2>
    <p class="doc-p">
        서비스는 이용자의 개인정보를 제3자에게 판매·제공하지 않습니다. 분석 과정에서 네이버가 제공하는
        공개 API·페이지를 조회하며, 이는 분석 수행에 필요한 범위로 한정됩니다.
    </p>

    <h2 class="doc-h2 text-ink">6. 이용자의 권리</h2>
    <p class="doc-p">
        이용자는 언제든지 본인의 개인정보 열람·수정·삭제 및 회원 탈퇴를 요청할 수 있습니다.
        확장 프로그램은 로그아웃 또는 삭제로 로컬 데이터를 제거할 수 있습니다.
    </p>

    <h2 class="doc-h2 text-ink">7. 보안</h2>
    <p class="doc-p">
        비밀번호와 네이버 연동 자격증명 등 민감정보는 암호화하여 저장하며, 통신은 HTTPS로 보호됩니다.
    </p>

    <h2 class="doc-h2 text-ink">8. 문의처</h2>
    <p class="doc-p">
        개인정보 관련 문의: <a class="rf-link" href="mailto:jxamen@gmail.com" style="color:var(--color-primary);">jxamen@gmail.com</a>
        (또는 로그인 후 <b>고객센터 &gt; 1:1 문의</b>)
    </p>
    <p class="text-muted" style="margin-top:24px;font-size:var(--fs-xs);">
        본 방침은 관련 법령·서비스 정책 변경에 따라 개정될 수 있으며, 개정 시 본 페이지에 공지합니다.
    </p>
</section>
@endsection
