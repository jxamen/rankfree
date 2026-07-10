@php
    $uid = $uid ?? uniqid('ip');
    $value = (string) ($value ?? '');
    $name = $name ?? 'icon';
@endphp
{{-- crm menu_group_edit 아이콘 피커 이식: 미리보기 + 입력 + 검색 + 프리셋/전체 그리드 --}}
<div class="icon-picker" data-uid="{{ $uid }}">
    <div class="flex items-center gap-2">
        <span class="ip-preview">@if (str_contains($value, 'fa-'))<i class="{{ $value }}"></i>@endif</span>
        <input type="text" name="{{ $name }}" class="input ip-input" value="{{ $value }}" maxlength="60" placeholder="예: fas fa-users" style="flex:1;min-width:150px;">
    </div>
    <div class="flex items-center gap-2 mt-2">
        <input type="text" class="input ip-search" placeholder="아이콘 검색 (영문: user, chart, truck …)" autocomplete="off" style="flex:1;">
        <button type="button" class="btn btn-secondary btn-sm ip-clear" title="검색 비우기">×</button>
    </div>
    <div class="ip-hint text-muted-soft" style="font-size:11px;margin-top:5px;">자주 쓰는 아이콘입니다. 검색하면 무료 아이콘 전체에서 찾습니다. (영문 키워드)</div>
    <div class="ip-grid"></div>
</div>
