{{-- 메뉴 SEO 입력 (타이틀·디스크립션·키워드) — 항목 등록/수정 폼 공용.
     $menu: 수정 시 Menu, 등록 시 null. 페이지에 노출될 <meta>·<title> 폴백값이 된다. --}}
@php $menu = $menu ?? null; @endphp
<details class="rf-seo" style="flex-basis:100%;margin-top:8px;" @if ($menu && ($menu->meta_title || $menu->meta_description || $menu->meta_keywords)) open @endif>
    <summary class="cursor-pointer text-muted" style="font-size:var(--fs-xs);font-weight:600;">
        🔍 SEO 설정 <span class="text-muted-soft" style="font-weight:400;">타이틀·디스크립션·키워드 (검색엔진 노출용, 비우면 기본값)</span>
    </summary>
    <div class="flex gap-3 flex-wrap mt-2">
        <div style="flex-basis:100%;">
            <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">SEO 타이틀 <span class="text-muted-soft">(브라우저 탭·검색결과 제목 · 비우면 메뉴명)</span></label>
            <input name="meta_title" class="input" maxlength="150" value="{{ $menu?->meta_title }}" placeholder="예: 무료 플레이스 순위 조회 · 랭크프리">
        </div>
        <div style="flex-basis:100%;">
            <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">메타 디스크립션 <span class="text-muted-soft">(검색결과 요약 · 권장 70~160자)</span></label>
            <textarea name="meta_description" class="input" maxlength="255" style="height:56px;padding-top:8px;resize:vertical;" placeholder="이 페이지를 한 문장으로 요약하세요.">{{ $menu?->meta_description }}</textarea>
        </div>
        <div style="flex-basis:100%;">
            <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">메타 키워드 <span class="text-muted-soft">(쉼표로 구분)</span></label>
            <input name="meta_keywords" class="input" maxlength="255" value="{{ $menu?->meta_keywords }}" placeholder="네이버 순위, 플레이스 순위, 키워드 분석">
        </div>
    </div>
</details>
