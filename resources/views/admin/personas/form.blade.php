@extends('admin.layout')
@section('page-title', $persona->exists ? '페르소나 수정' : '새 페르소나')

@section('admin-content')
@php
    $interestsStr = implode(', ', (array) old('interests', $persona->interests ?? []));
    $hours = (array) old('active_hours', $persona->active_hours ?? []);
    $prefCats = array_map('intval', (array) old('preferred_categories', $persona->preferred_categories ?? []));
@endphp

<form method="POST" action="{{ $persona->exists ? route('admin.personas.update', $persona) : route('admin.personas.store') }}" class="max-w-3xl">
    @csrf
    @if ($persona->exists) @method('PUT') @endif

    @if ($errors->any())
        <div class="card-soft px-4 py-3 mb-4" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
    @endif

    {{-- 프로필 --}}
    <div class="card p-5 mb-4">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">프로필</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">닉네임 *</label>
                <input name="nickname" value="{{ old('nickname', $persona->nickname) }}" class="input" required>
            </div>
            <div>
                <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">아바타 색상</label>
                <input type="color" name="avatar_color" value="{{ old('avatar_color', $persona->avatar_color ?: '#0052ff') }}" class="input" style="height:40px;padding:4px;">
            </div>
            <div class="sm:col-span-2">
                <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">한줄 소개</label>
                <input name="bio" value="{{ old('bio', $persona->bio) }}" class="input" maxlength="200" placeholder="예: 맛집 좋아하는 느긋한 사람">
            </div>
        </div>
    </div>

    {{-- 인구통계·성향 --}}
    <div class="card p-5 mb-4">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">인구통계 · 성향</div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">나이</label>
                <input type="number" name="age" value="{{ old('age', $persona->age) }}" min="10" max="99" class="input">
            </div>
            <div>
                <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">성별</label>
                <select name="gender" class="input">
                    @foreach (App\Models\Persona::GENDERS as $v => $lbl)
                        <option value="{{ $v }}" @selected(old('gender', $persona->gender) === $v)>{{ $lbl }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">지역</label>
                <input name="region" value="{{ old('region', $persona->region) }}" class="input" placeholder="서울">
            </div>
            <div class="sm:col-span-3">
                <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">관심사 <span class="text-muted-soft">(콤마로 구분)</span></label>
                <input name="interests" value="{{ $interestsStr }}" class="input" placeholder="맛집, 카페, 뷰티">
            </div>
            <div>
                <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">말투</label>
                <select name="tone" class="input">
                    @foreach (App\Models\Persona::TONES as $v => $lbl)
                        <option value="{{ $v }}" @selected(old('tone', $persona->tone) === $v)>{{ $lbl }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">이모지 사용</label>
                <select name="emoji_level" class="input">
                    <option value="0" @selected((int) old('emoji_level', $persona->emoji_level) === 0)>없음</option>
                    <option value="1" @selected((int) old('emoji_level', $persona->emoji_level) === 1)>보통</option>
                    <option value="2" @selected((int) old('emoji_level', $persona->emoji_level) === 2)>자주</option>
                </select>
            </div>
            <div>
                <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">글 길이</label>
                <select name="post_length" class="input">
                    @foreach (App\Models\Persona::POST_LENGTHS as $v => $lbl)
                        <option value="{{ $v }}" @selected(old('post_length', $persona->post_length) === $v)>{{ $lbl }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- 활동 설정 --}}
    <div class="card p-5 mb-4">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">활동 설정</div>
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
            <div>
                <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">활동 수준</label>
                <select name="activity_level" class="input">
                    @foreach (App\Models\Persona::ACTIVITY_LEVELS as $v => $lbl)
                        <option value="{{ $v }}" @selected(old('activity_level', $persona->activity_level) === $v)>{{ $lbl }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">글 빈도 (0~10)</label>
                <input type="number" name="post_weight" value="{{ old('post_weight', $persona->post_weight) }}" min="0" max="10" class="input">
            </div>
            <div>
                <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">댓글 빈도 (0~10)</label>
                <input type="number" name="comment_weight" value="{{ old('comment_weight', $persona->comment_weight) }}" min="0" max="10" class="input">
            </div>
            <div>
                <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">좋아요 빈도 (0~10)</label>
                <input type="number" name="like_weight" value="{{ old('like_weight', $persona->like_weight) }}" min="0" max="10" class="input">
            </div>
        </div>
        <div class="mt-4">
            <label class="text-muted block mb-2" style="font-size:var(--fs-xs);">주 활동 시간대</label>
            <div class="flex flex-wrap gap-3">
                @foreach (App\Models\Persona::ACTIVE_HOURS as $v => $lbl)
                    <label class="flex items-center gap-1.5" style="font-size:var(--fs-xs);">
                        <input type="checkbox" name="active_hours[]" value="{{ $v }}" @checked(in_array($v, $hours, true)) style="accent-color:var(--color-primary);"> {{ $lbl }}
                    </label>
                @endforeach
            </div>
        </div>
        <div class="mt-4">
            <label class="text-muted block mb-2" style="font-size:var(--fs-xs);">선호 게시판</label>
            <div class="flex flex-wrap gap-3">
                @foreach ($categories as $cat)
                    <label class="flex items-center gap-1.5" style="font-size:var(--fs-xs);">
                        <input type="checkbox" name="preferred_categories[]" value="{{ $cat->id }}" @checked(in_array($cat->id, $prefCats, true)) style="accent-color:var(--color-primary);"> {{ $cat->icon }} {{ $cat->name }}
                    </label>
                @endforeach
            </div>
        </div>
        <div class="mt-4 flex items-center gap-6">
            <label class="flex items-center gap-2" style="font-size:var(--fs-xs);">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $persona->is_active)) style="accent-color:var(--color-primary);"> 활성
            </label>
            <label class="flex items-center gap-2" style="font-size:var(--fs-xs);">
                <input type="checkbox" name="auto_active" value="1" @checked(old('auto_active', $persona->auto_active)) style="accent-color:var(--color-primary);"> 자동활동
            </label>
        </div>
    </div>

    <div class="flex items-center gap-2">
        <button type="submit" class="btn btn-primary">{{ $persona->exists ? '저장' : '추가' }}</button>
        <a href="{{ route('admin.personas') }}" class="btn btn-secondary">취소</a>
    </div>
</form>
@endsection
