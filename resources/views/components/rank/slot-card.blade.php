{{--
    순위추적 슬롯 카드 (공용) — 콘솔(내 슬롯)·어드민(전 회원 열람)이 같은 카드를 쓴다.
    이렇게 한 컴포넌트로 모아 콘솔↔어드민 UI가 갈라지지 않게 한다.

    props:
      rankSlot    슬롯 모델(PlaceRankSlot | ShopRankSlot) — 'slot' 은 Blade 컴포넌트 예약어라 rankSlot 사용
      mode        'place' | 'shop'
      area        'console'(내 것, 수정/삭제 가능) | 'admin'(열람, 수정/삭제 없음)
      showMember  회원(업체) 뱃지 표시 — 어드민 전 회원 목록에서 회원별 필터 링크
      from,to     기간 필터(날짜별 셀 파셜에 전달)

    JS는 rank.partials._card-scripts(공용) 가 담당(.rf-run-form·#rf-run-all·접기·rfCopyShare).
    이미지 저장(rfSaveReportImage)·캡처 CSS 는 console.partials._image-save.
--}}
@props(['rankSlot', 'mode' => 'place', 'area' => 'console', 'showMember' => false, 'from' => null, 'to' => null])
@php
    $slot = $rankSlot;   // 로컬 별칭(가독성)
    $isPlace = $mode === 'place';
    $isAdmin = $area === 'admin';

    // 라우트 이름 — 콘솔/어드민, 플레이스/쇼핑에 따라
    if ($isAdmin) {
        $seg = $isPlace ? 'place' : 'shop';
        $toggleRoute = "admin.{$seg}-tracking.toggle";
        $runRoute = "admin.{$seg}-tracking.run";
        $listRoute = "admin.{$seg}-tracking";
    } else {
        $seg = $isPlace ? 'rank' : 'shop-rank';
        $toggleRoute = "console.{$seg}.toggle";
        $runRoute = "console.{$seg}.run";
        $updateRoute = "console.{$seg}.update";
        $destroyRoute = "console.{$seg}.destroy";
    }
    $cellsView = $isPlace ? 'rank.partials.cells' : 'shop-rank.partials.cells';

    // 키워드 검색 링크 · 대상(플레이스/상품) 이름·URL · 캡처 문구
    if ($isPlace) {
        $searchUrl = 'https://m.place.naver.com/place/list?query='.urlencode((string) $slot->keyword);
        $targetName = ($slot->label ? $slot->label.' · ' : '').($slot->place_name ?: ($slot->place_id ? 'ID '.$slot->place_id : ''));
        $targetUrl = $slot->place_url ?: ($slot->place_id ? 'https://m.place.naver.com/'.($slot->category ?: 'place').'/'.$slot->place_id : null);
        $targetTitle = '플레이스 페이지 열기';
        $capBadge = '순위 추적 · 랭크프리';
        $capNoun = '순위 추적';
        $imgName = '랭크프리-순위-'.$slot->keyword.'.png';
        $editTargetKey = 'place';
        $editTargetVal = $slot->place_url ?: ($slot->place_id ?: $slot->place_name);
        $stopHint = '3일 연속 미노출(300위 밖) 시 자동 중단됩니다 — [재개]로 다시 켤 수 있어요';
    } else {
        $searchUrl = 'https://search.shopping.naver.com/search/all?query='.urlencode((string) $slot->keyword);
        $targetName = $slot->product_title ?: ($slot->mall_name ?: ($slot->product_id ? 'ID '.$slot->product_id : ''));
        $targetUrl = $slot->product_url;
        $targetTitle = '상품 페이지 열기';
        $capBadge = '쇼핑 순위추적 · 랭크프리';
        $capNoun = '쇼핑 순위추적';
        $imgName = '랭크프리-쇼핑순위-'.$slot->keyword.'.png';
        $editTargetKey = 'target';
        $editTargetVal = $slot->product_url ?: ($slot->mall_name ?: $slot->product_id);
        $stopHint = '3일 연속 미노출(1000위 밖) 시 자동 중단됩니다 — [재개]로 다시 켤 수 있어요';
    }
@endphp
<div id="rf-slot-report-{{ $slot->id }}" class="mb-4">
    {{-- 캡처 전용 상단 브랜딩 --}}
    <div class="rf-cap-only" style="align-items:center;justify-content:space-between;gap:8px;margin-bottom:12px;">
        <span class="badge border border-hairline">{{ $capBadge }}</span>
        <span class="text-muted-soft" style="font-size:var(--fs-xs);">rankfree.kr</span>
    </div>
    <div class="card overflow-hidden rf-slot" data-slot="{{ $slot->id }}">
        {{-- 헤더: 키워드 · 대상 · (회원) · 최종수집 · 액션 --}}
        <div class="flex items-center gap-3 px-5 py-3 border-b border-hairline-soft flex-wrap" style="background:var(--color-surface-soft);">
            <a href="{{ $searchUrl }}" target="_blank" rel="noopener"
               class="text-ink font-semibold hover:underline" style="font-size:var(--fs-sm);" title="네이버에서 이 키워드 검색">{{ $slot->keyword }}</a>
            {{-- 모바일 전용 순위체크 — 키워드 우측. 실제 실행은 아래 rf-run-form 제출(전체 순위체크 중복 방지) --}}
            <button type="button" class="btn btn-secondary btn-sm sm:hidden rf-cap-hide"
                    onclick="this.closest('.rf-slot').querySelector('.rf-run-form').requestSubmit()">순위체크</button>
            @if ($targetUrl)
                <a href="{{ $targetUrl }}" target="_blank" rel="noopener" class="text-muted hover:text-ink truncate" style="font-size:var(--fs-xs);max-width:420px;" title="{{ $targetTitle }}">{{ $targetName }}</a>
            @else
                <span class="text-muted truncate" style="font-size:var(--fs-xs);max-width:420px;">{{ $targetName ?: '—' }}</span>
            @endif
            {{-- 회원(업체) 뱃지 — 어드민 전 회원 목록: 클릭 시 그 회원 추적만 --}}
            @if ($showMember && $slot->user)
                <a href="{{ route($listRoute, ['user' => $slot->user_id]) }}" class="badge border border-hairline hover:text-ink rf-cap-hide"
                   style="font-size:var(--fs-xs);" title="{{ $slot->user->name }} 회원의 추적만 보기">{{ $slot->user->name }}</a>
            @endif
            @if ($slot->last_checked_at)
                <span class="text-muted-soft" style="font-size:var(--fs-xs);" title="마지막 순위 수집 시각">최종 수집 {{ $slot->last_checked_at->timezone('Asia/Seoul')->format('m-d H:i') }}</span>
            @endif
            <div class="flex-1"></div>
            {{-- 액션 — 모바일에서 잘리지 않게 줄바꿈 허용, 순위체크는 데스크톱만(모바일은 위 대상명 옆) --}}
            <div class="flex items-center gap-1 flex-wrap rf-cap-hide">
                @unless ($slot->is_active)
                    <span class="badge" style="font-size:var(--fs-xs);color:var(--color-error);" title="{{ $stopHint }}">체크 중단됨</span>
                @endunless
                <form method="POST" action="{{ route($toggleRoute, $slot) }}">@csrf
                    <button type="submit" class="btn btn-ghost btn-sm" title="{{ $slot->is_active ? '자동 순위체크 일시 중단(기록 유지)' : '자동 순위체크 재개' }}">{{ $slot->is_active ? '중단' : '재개' }}</button>
                </form>
                <form method="POST" action="{{ route($runRoute, $slot) }}" class="rf-run-form hidden sm:block" data-keyword="{{ $slot->keyword }}">@csrf<button type="submit" class="btn btn-secondary btn-sm">순위체크</button></form>
                @if ($isPlace)
                    <button type="button" class="btn btn-ghost btn-sm rf-metrics-toggle" title="리뷰·저장 접기/펼치기">접기</button>
                @endif
                @if ($slot->slug)
                    <button type="button" class="btn btn-ghost btn-sm" title="공유 링크 복사 (로그인 없이 열람)"
                            onclick="rfCopyShare(this, @js($slot->shareUrl()))">공유</button>
                @endif
                <button type="button" class="btn btn-ghost btn-sm"
                        onclick="rfSaveReportImage('rf-slot-report-{{ $slot->id }}', @js($imgName), this)" title="이 키워드 순위를 PNG 이미지로 저장">🖼 이미지</button>
                @unless ($isAdmin)
                    <button type="button" class="btn btn-ghost btn-sm rf-edit-btn"
                            data-action="{{ route($updateRoute, $slot) }}"
                            data-slot-id="{{ $slot->id }}"
                            data-keyword="{{ $slot->keyword }}"
                            data-{{ $editTargetKey }}="{{ $editTargetVal }}"
                            data-label="{{ $slot->label }}">수정</button>
                    <form method="POST" action="{{ route($destroyRoute, $slot) }}" onsubmit="return confirm('삭제하시겠습니까?')">@csrf @method('DELETE')<button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-error);">삭제</button></form>
                @endunless
            </div>
        </div>

        {{-- 날짜별 순위 카드 (최신순) — 공개 리포트와 공용 파셜, 기간 필터 전달 --}}
        @include($cellsView, ['slot' => $slot, 'from' => $from, 'to' => $to])
    </div>
    {{-- 캡처 전용 하단 홍보 문구 --}}
    <div class="rf-cap-only" style="flex-direction:column;align-items:center;gap:4px;margin-top:12px;border-top:1px solid var(--color-hairline);padding-top:12px;text-align:center;">
        <span class="text-muted" style="font-size:var(--fs-xs);">이 리포트는 <b class="text-ink">랭크프리</b>에서 {{ $capNoun }}으로 생성되었습니다.</span>
        <span class="text-muted" style="font-size:var(--fs-xs);">네이버에서 <b class="text-ink">랭크프리</b>를 검색 방문하고 무료로 내 순위를 확인해보세요.</span>
    </div>
</div>
