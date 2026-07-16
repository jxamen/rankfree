{{-- 동적 주문 필드 1개 렌더 (인라인·스텝 공통). 변수: $f(ProductField), $minDate --}}
@php $name = 'f_'.$f->field_key; $old = old($name); @endphp
<div @if ($f->is_required) data-required="1" @endif>
    <label class="text-ink" style="font-size:var(--fs-xs);font-weight:600;">{{ $f->label }} @if ($f->is_required)<span style="color:var(--color-error);">*</span>@endif</label>
    @switch($f->field_type)
        @case('TEXTAREA')
            <textarea name="{{ $name }}" rows="3" placeholder="{{ $f->placeholder }}" class="input mt-1" style="width:100%;resize:vertical;">{{ $old }}</textarea>
            @break
        @case('NUMBER')
            <input type="number" step="any" name="{{ $name }}" value="{{ $old }}" placeholder="{{ $f->placeholder }}" class="input mt-1" style="width:100%;">
            @break
        @case('URL')
            <input type="url" name="{{ $name }}" value="{{ $old }}" placeholder="{{ $f->placeholder ?: 'https://' }}" class="input mt-1" style="width:100%;">
            @break
        @case('DATE')
            <input type="date" name="{{ $name }}" value="{{ $old }}" min="{{ $minDate }}" class="input mt-1" style="width:100%;">
            @break
        @case('SELECT')
            <select name="{{ $name }}" class="input mt-1" style="width:100%;">
                <option value="">선택하세요</option>
                @foreach ($f->options() as $o)<option value="{{ $o['value'] }}" {{ $old === $o['value'] ? 'selected' : '' }}>{{ $o['label'] }}</option>@endforeach
            </select>
            @break
        @case('MULTI_SELECT')
            <div class="flex flex-wrap gap-3 mt-2">
                @foreach ($f->options() as $o)
                    <label class="inline-flex items-center gap-1.5" style="font-size:var(--fs-xs);"><input type="checkbox" name="{{ $name }}[]" value="{{ $o['value'] }}" {{ is_array($old) && in_array($o['value'], $old) ? 'checked' : '' }}> {{ $o['label'] }}</label>
                @endforeach
            </div>
            @break
        @case('TOGGLE')
            <label class="inline-flex items-center gap-2 mt-2" style="font-size:var(--fs-xs);"><input type="checkbox" name="{{ $name }}" value="1" {{ $old ? 'checked' : '' }}> 예</label>
            @break
        @case('TAGS')
            <input name="{{ $name }}" value="{{ $old }}" placeholder="{{ $f->placeholder ?: '쉼표로 구분' }}" class="input mt-1" style="width:100%;">
            @break
        @case('FILE')
        @case('IMAGE')
            <input type="file" name="{{ $name }}" @if ($f->field_type === 'IMAGE') accept="image/*" @endif class="mt-1" style="width:100%;font-size:var(--fs-xs);">
            @break
        @default
            <input name="{{ $name }}" value="{{ $old }}" placeholder="{{ $f->placeholder }}" class="input mt-1" style="width:100%;">
    @endswitch
    @if ($f->help_text)<div class="text-muted-soft mt-1" style="font-size:var(--fs-xs);">{{ $f->help_text }}</div>@endif
</div>
