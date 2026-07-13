{{-- 단일 설정 필드 — $name(폼필드), $label, $value, $secret(bool), $placeholder(선택) --}}
@php $secret = $secret ?? false; $ph = $placeholder ?? ''; @endphp
<div class="mb-3" style="max-width:560px;">
    <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;display:block;margin-bottom:5px;">{{ $label }}</label>
    @if ($secret)
        <div class="rf-secret" style="position:relative;">
            <input type="password" name="{{ $name }}" value="{{ old($name, $value) }}" placeholder="{{ $ph }}" autocomplete="off" class="input rf-secret-input" style="width:100%;padding-right:64px;font-family:var(--font-mono);font-size:var(--fs-xs);">
            <span style="position:absolute;right:6px;top:50%;transform:translateY(-50%);display:flex;gap:2px;">
                <button type="button" class="rf-secret-show" title="보기" style="border:0;background:none;color:var(--color-muted);cursor:pointer;padding:4px 6px;"><i class="fa-regular fa-eye"></i></button>
                <button type="button" class="rf-secret-copy" title="복사" style="border:0;background:none;color:var(--color-muted);cursor:pointer;padding:4px 6px;"><i class="fa-regular fa-copy"></i></button>
            </span>
        </div>
    @else
        <input type="text" name="{{ $name }}" value="{{ old($name, $value) }}" placeholder="{{ $ph }}" autocomplete="off" class="input" style="width:100%;font-family:var(--font-mono);font-size:var(--fs-xs);">
    @endif
</div>
