@extends('admin.layout')
@section('page-title', '페르소나 관리')

@section('page-actions')
    <div class="flex items-center gap-2">
        <a href="{{ route('admin.community-seeds') }}" class="btn btn-secondary btn-sm">📝 글밥 관리</a>
        <form method="POST" action="{{ route('admin.personas.simulate') }}" class="flex items-center gap-1">
            @csrf
            <input type="number" name="count" value="10" min="1" max="50" class="input" style="height:32px;width:70px;font-size:var(--fs-xs);" title="생성할 활동 수">
            <button type="submit" class="btn btn-secondary btn-sm">▶ 지금 활동 생성</button>
        </form>
        <form method="POST" action="{{ route('admin.personas.generate') }}" onsubmit="return confirm('부족분을 랜덤 페르소나로 채웁니다. 계속할까요?');">
            @csrf
            <button type="submit" class="btn btn-secondary btn-sm">⚡ 50개 일괄 생성</button>
        </form>
        <a href="{{ route('admin.personas.create') }}" class="btn btn-primary btn-sm">+ 새 페르소나</a>
    </div>
@endsection

@section('admin-content')
<x-console.page-head title="페르소나 관리" desc="커뮤니티 자동 활동용 가상 페르소나 관리 · 글밥(수집 소재)을 바탕으로 글·댓글을 생성합니다" />
@php
    $toneLabels = App\Models\Persona::TONES;
    $levelLabels = App\Models\Persona::ACTIVITY_LEVELS;
    $genderLabels = App\Models\Persona::GENDERS;
@endphp

<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
    <div class="card p-4">
        <div class="text-muted" style="font-size:var(--fs-xs);">전체 페르소나</div>
        <div class="font-display text-ink mt-1" style="font-size:var(--fs-xl);">{{ number_format($total) }}<span class="text-muted-soft" style="font-size:var(--fs-sm);">명</span></div>
    </div>
    <div class="card p-4">
        <div class="text-muted" style="font-size:var(--fs-xs);">자동활동 중</div>
        <div class="font-display text-ink mt-1" style="font-size:var(--fs-xl);">{{ number_format($activeCount) }}<span class="text-muted-soft" style="font-size:var(--fs-sm);">명</span></div>
    </div>
    <div class="card p-4">
        <div class="text-muted" style="font-size:var(--fs-xs);">콘텐츠 생성</div>
        <div class="mt-1" style="font-size:var(--fs-sm);">
            @if ($apiEnabled)
                <span style="color:var(--color-success);font-weight:600;">Claude API 연결됨</span>
            @else
                <span style="color:var(--color-warning);font-weight:600;">템플릿 폴백</span>
                <span class="text-muted-soft" style="font-size:var(--fs-xs);">— .env에 ANTHROPIC_API_KEY 설정 시 AI 생성</span>
            @endif
        </div>
    </div>
</div>

<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:1000px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);border-bottom:1px solid var(--color-hairline-soft);">
                    <th class="text-left px-4 py-3 font-semibold">닉네임</th>
                    <th class="text-left px-3 py-3 font-semibold" style="width:110px;">나이·성별</th>
                    <th class="text-left px-3 py-3 font-semibold">관심사</th>
                    <th class="text-center px-3 py-3 font-semibold" style="width:80px;">말투</th>
                    <th class="text-center px-3 py-3 font-semibold" style="width:70px;">활동</th>
                    <th class="text-right px-3 py-3 font-semibold" style="width:90px;">글·댓글</th>
                    <th class="text-center px-3 py-3 font-semibold" style="width:80px;">자동활동</th>
                    <th class="text-right px-4 py-3 font-semibold" style="width:120px;">관리</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($personas as $p)
                    <tr style="border-top:1px solid var(--color-hairline-soft);{{ $p->is_active ? '' : 'opacity:.5;' }}">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span style="width:28px;height:28px;flex:none;border-radius:50%;display:grid;place-items:center;background:{{ $p->avatar_color ?: '#0052ff' }};color:#fff;font-size:var(--fs-xs);font-weight:700;">{{ $p->initial() }}</span>
                                <div style="min-width:0;">
                                    <a href="{{ route('admin.personas.edit', $p) }}" class="text-ink font-semibold hover:underline" style="font-size:var(--fs-xs);">{{ $p->nickname }}</a>
                                    @if ($p->bio)<div class="text-muted-soft truncate" style="font-size:var(--fs-xs);max-width:200px;">{{ $p->bio }}</div>@endif
                                </div>
                            </div>
                        </td>
                        <td class="px-3 py-3 text-muted" style="font-size:var(--fs-xs);">{{ $p->age ? $p->age.'세' : '—' }} · {{ $genderLabels[$p->gender] ?? '—' }}</td>
                        <td class="px-3 py-3">
                            <div class="flex flex-wrap gap-1" style="max-width:240px;">
                                @foreach (array_slice((array) $p->interests, 0, 4) as $it)
                                    <span class="badge" style="font-size:var(--fs-xs);padding:1px 7px;">{{ $it }}</span>
                                @endforeach
                            </div>
                        </td>
                        <td class="px-3 py-3 text-center text-body" style="font-size:var(--fs-xs);">{{ $toneLabels[$p->tone] ?? $p->tone }}</td>
                        <td class="px-3 py-3 text-center text-body" style="font-size:var(--fs-xs);">{{ $levelLabels[$p->activity_level] ?? $p->activity_level }}</td>
                        <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ $p->posts_count }} · {{ $p->comments_count }}</td>
                        <td class="px-3 py-3 text-center">
                            <form method="POST" action="{{ route('admin.personas.toggle', $p) }}">
                                @csrf
                                <button type="submit" class="badge" style="font-size:var(--fs-xs);padding:2px 9px;cursor:pointer;{{ $p->auto_active ? 'background:color-mix(in srgb,var(--color-success) 14%,var(--color-canvas));color:var(--color-success);' : '' }}">{{ $p->auto_active ? 'ON' : 'OFF' }}</button>
                            </form>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.personas.edit', $p) }}" class="text-muted hover:text-ink" style="font-size:var(--fs-xs);">수정</a>
                            <form method="POST" action="{{ route('admin.personas.destroy', $p) }}" class="inline" onsubmit="return confirm('삭제할까요?');">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-muted-soft hover:text-error" style="font-size:var(--fs-xs);background:none;border:0;cursor:pointer;margin-left:6px;">삭제</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted-soft" style="padding:40px;font-size:var(--fs-xs);">페르소나가 없습니다. "50개 일괄 생성"으로 시작하세요.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-4">{{ $personas->links() }}</div>
@endsection
