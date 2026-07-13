{{--
    자격증명 그룹(다중) 편집 UI — 환경 설정 공용.
    입력: $g(접두), $title, $desc, $plain(일반필드 배열), $secret(시크릿 필드), $labels(필드→라벨), $rows(기존), $live(적용중 수)
    모든 값은 항상 수정 가능. 시크릿은 기본 가림(보기/복사). 삭제는 즉시 라인 제거(저장 시 반영).
--}}
<div class="card p-5 mb-4">
    <div class="flex items-center gap-2 mb-1">
        <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">{{ $title }}</span>
        <span class="badge" style="font-size:var(--fs-xs);padding:2px 9px;{{ $live > 0 ? 'background:color-mix(in srgb,var(--color-success) 14%,var(--color-canvas));color:var(--color-success);' : '' }}">{{ $live }}개 적용중</span>
    </div>
    <p class="text-muted-soft mb-4" style="font-size:var(--fs-xs);">{{ $desc }}</p>

    <div class="rf-cred-wrap" data-group="{{ $g }}">
        @forelse ($rows as $row)
            @include('admin.settings._credrow', ['g' => $g, 'plain' => $plain, 'secret' => $secret, 'labels' => $labels, 'row' => $row])
        @empty
            @include('admin.settings._credrow', ['g' => $g, 'plain' => $plain, 'secret' => $secret, 'labels' => $labels, 'row' => []])
        @endforelse
    </div>

    {{-- 줄 추가용 빈 줄 템플릿(폼 전송 안 됨) --}}
    <template class="rf-cred-tpl" data-group="{{ $g }}">
        @include('admin.settings._credrow', ['g' => $g, 'plain' => $plain, 'secret' => $secret, 'labels' => $labels, 'row' => []])
    </template>

    <button type="button" class="btn btn-secondary btn-sm rf-cred-add mt-1" data-group="{{ $g }}">＋ 줄 추가</button>
</div>
