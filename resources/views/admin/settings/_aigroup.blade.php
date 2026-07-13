{{--
    AI 모델 API 키 그룹 — 공급자별 키(다중). rf-cred-* 인프라(data-group="ai") 재사용.
    입력: $rows(기존), $providers(선택지), $live(적용중 수)
--}}
<div class="card p-5 mb-4">
    <div class="flex items-center gap-2 mb-1">
        <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">AI 모델 API 키</span>
        <span class="badge" style="font-size:var(--fs-xs);padding:2px 9px;{{ $live > 0 ? 'background:color-mix(in srgb,var(--color-success) 14%,var(--color-canvas));color:var(--color-success);' : '' }}">{{ $live }}개 적용중</span>
    </div>
    <p class="text-muted-soft mb-4" style="font-size:var(--fs-xs);">
        커뮤니티 글·댓글 자동 생성 등 AI 기능에 사용합니다. Claude(Anthropic)·Gemini(Google)·OpenAI 공급자별 키를 등록하세요. 공급자별 <b>첫 키</b>가 대표로 적용됩니다.
    </p>

    <div class="rf-cred-wrap" data-group="ai">
        @forelse ($rows as $row)
            @include('admin.settings._airow', ['row' => $row, 'providers' => $providers])
        @empty
            @include('admin.settings._airow', ['row' => [], 'providers' => $providers])
        @endforelse
    </div>

    <template class="rf-cred-tpl" data-group="ai">
        @include('admin.settings._airow', ['row' => [], 'providers' => $providers])
    </template>

    <button type="button" class="btn btn-secondary btn-sm rf-cred-add mt-1" data-group="ai">＋ 줄 추가</button>
</div>
