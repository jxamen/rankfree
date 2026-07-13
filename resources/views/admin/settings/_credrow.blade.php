{{--
    자격증명 한 줄 — 일반필드 + 시크릿(기본 가림·보기 토글·복사) + 즉시 삭제.
    입력: $g(접두), $plain(일반필드), $secret(시크릿 필드), $labels(필드→라벨), $row(값, 없으면 빈 줄)
    폼 필드는 그룹 균일 배열: {g}_{field}[] · {g}_{secret}[] — 저장 시 인덱스로 zip.
--}}
@php $row = $row ?? []; @endphp
<div class="flex items-center gap-2 mb-2 rf-cred-row">
    @foreach ($plain as $f)
        <input name="{{ $g }}_{{ $f }}[]" value="{{ $row[$f] ?? '' }}" class="input" style="flex:1;" autocomplete="off" placeholder="{{ $labels[$f] ?? $f }}">
    @endforeach
    <div class="rf-secret" style="flex:1;display:flex;align-items:center;gap:4px;">
        <input type="password" name="{{ $g }}_{{ $secret }}[]" value="{{ $row[$secret] ?? '' }}" class="input rf-secret-input" style="flex:1;" autocomplete="new-password" placeholder="{{ $labels[$secret] ?? $secret }}">
        <button type="button" class="btn btn-ghost btn-sm rf-secret-show flex-none" title="보기/가리기" aria-label="보기"><i class="fa-regular fa-eye"></i></button>
        <button type="button" class="btn btn-ghost btn-sm rf-secret-copy flex-none" title="복사" aria-label="복사"><i class="fa-regular fa-copy"></i></button>
    </div>
    <button type="button" class="btn btn-ghost btn-sm rf-cred-del flex-none" title="이 줄 삭제" aria-label="삭제" style="color:var(--color-error);"><i class="fa-solid fa-xmark"></i></button>
</div>
