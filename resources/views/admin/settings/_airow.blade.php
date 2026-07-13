{{--
    AI 모델 키 한 줄 — 공급자 선택 + 키(가림·보기·복사) + 즉시 삭제.
    입력: $row(값, 없으면 빈 줄), $providers(value→라벨). 폼 필드: ai_provider[] · ai_key[].
    show/copy/delete 는 _credrow 와 동일 클래스(rf-secret-*, rf-cred-del)로 공용 JS 재사용.
--}}
@php $row = $row ?? []; $sel = $row['provider'] ?? array_key_first($providers); @endphp
<div class="flex items-center gap-2 mb-2 rf-cred-row">
    <select name="ai_provider[]" class="input" style="flex:0 0 210px;">
        @foreach ($providers as $val => $label)
            <option value="{{ $val }}" @selected($sel === $val)>{{ $label }}</option>
        @endforeach
    </select>
    <div class="rf-secret" style="flex:1;display:flex;align-items:center;gap:4px;">
        <input type="password" name="ai_key[]" value="{{ $row['api_key'] ?? '' }}" class="input rf-secret-input" style="flex:1;" autocomplete="new-password" placeholder="API 키">
        <button type="button" class="btn btn-ghost btn-sm rf-secret-show flex-none" title="보기/가리기" aria-label="보기"><i class="fa-regular fa-eye"></i></button>
        <button type="button" class="btn btn-ghost btn-sm rf-secret-copy flex-none" title="복사" aria-label="복사"><i class="fa-regular fa-copy"></i></button>
    </div>
    <button type="button" class="btn btn-ghost btn-sm rf-cred-del flex-none" title="이 줄 삭제" aria-label="삭제" style="color:var(--color-error);"><i class="fa-solid fa-xmark"></i></button>
</div>
